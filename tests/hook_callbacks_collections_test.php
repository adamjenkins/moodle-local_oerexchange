<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\profile_manager;
use local_oerexchange\local\resource_manager;

/**
 * Social-preview tags for the three kinds of shareable page this plugin
 * publishes, with the collection cases added.
 *
 * before_standard_head_html_generation() fires on every page load site-wide
 * and is now a dispatcher over three path gates, so there are two distinct
 * risks and both are asserted here.
 *
 * The first is LEAKAGE. An og:title is world-readable by definition — a
 * crawler is always a stranger — so the states whose pages show something only
 * to their owner or to a moderator must emit NOTHING: a draft, a collection a
 * moderator has taken down, a tombstone, and an unpublished resource. Each of
 * those is a different column, so each gets its own case rather than one
 * combined "not public" test.
 *
 * The second is the gate itself. The three path tests run before any query and
 * are matched against the END of $PAGE->url's path (PHPUnit's own wwwroot is a
 * subdirectory, which is also how a real subdirectory install looks), so an
 * ordinary page elsewhere on the site must stay free of these tags even when
 * a collection with that slug exists.
 *
 * The listener is called directly against a hand-built hook instance, the
 * arrangement tests/hook_callbacks_test.php already uses and explains.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(hook_callbacks::class)]
final class hook_callbacks_collections_test extends \advanced_testcase {
    /**
     * Build a fresh before_standard_head_html_generation hook instance.
     *
     * $PAGE->get_renderer('core') rather than the global $OUTPUT: in the
     * PHPUnit CLI bootstrap that global is a bootstrap_renderer, which the
     * hook constructor's typed parameter rejects.
     *
     * @return \core\hook\output\before_standard_head_html_generation
     */
    private function make_hook(): \core\hook\output\before_standard_head_html_generation {
        global $PAGE;

        return new \core\hook\output\before_standard_head_html_generation($PAGE->get_renderer('core'));
    }

    /**
     * The HTML the listener contributes for the current $PAGE->url.
     *
     * @return string
     */
    private function head_html(): string {
        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        return $hook->get_output();
    }

    /**
     * This plugin's data generator.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A collection in a known state.
     *
     * @param int $userid the owner
     * @param string $title
     * @param array $state columns to overwrite after creation
     * @return \stdClass the stored row
     */
    protected function make_collection(int $userid, string $title, array $state = []): \stdClass {
        return $this->generator()->create_collection($state + [
            'userid' => $userid,
            'title' => $title,
            // UNSPECIFIED MEANS DRAFT, the opposite of the generator's own
            // default: a draft must emit no preview tags at all, so that
            // state has to be explicit here.
            'visibility' => collection_manager::VISIBILITY_DRAFT,
            'timepublished' => 0,
        ]);
    }

    /**
     * A public collection with a description.
     *
     * @param int $userid
     * @param string $title
     * @param string $summary
     * @param array $state further columns to overwrite
     * @return \stdClass
     */
    protected function make_public_collection(
        int $userid,
        string $title,
        string $summary = '',
        array $state = []
    ): \stdClass {
        return $this->make_collection($userid, $title, $state + [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => time(),
            'summary' => $summary,
            'summaryformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Point $PAGE at a collection's real, component-prefixed request path.
     *
     * @param string $slug
     */
    protected function visit_collection(string $slug): void {
        global $PAGE;

        $PAGE->set_url(new \moodle_url('/local_oerexchange/c/' . $slug));
    }

    /**
     * Assert the whole Open Graph + Twitter tag set is present, with this
     * title and this og:type.
     *
     * @param string $html
     * @param string $title
     * @param string $type og:type
     */
    protected function assert_full_tag_set(string $html, string $title, string $type): void {
        $this->assertStringContainsString('property="og:title" content="' . $title . '"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('property="og:type" content="' . $type . '"', $html);
        // The four twitter twins, which make the link render as a card rather
        // than as a bare URL.
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('name="twitter:title" content="' . $title . '"', $html);
        $this->assertStringContainsString('name="twitter:description"', $html);
        $this->assertStringContainsString('name="twitter:image"', $html);
    }

    /**
     * A public collection page gets the full tag set, pointing at its real
     * routed URL, and is NOT marked noindex.
     *
     * @return void
     */
    public function test_a_public_collection_page_gets_the_full_tag_set(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection(
            (int) $owner->id,
            'Week one reading',
            '<p>Everything a first-year needs for week one.</p>'
        );

        $this->visit_collection($collection->slug);
        $html = $this->head_html();

        $this->assert_full_tag_set($html, 'Week one reading', 'website');
        $this->assertStringContainsString('Everything a first-year needs for week one.', $html);
        // The description is flattened to text first, so the markup never
        // reaches the attribute and its entities are escaped exactly once.
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
        // The canonical URL is the real, component-prefixed path; routed_path()
        // prepends '/r.php' or not depending on $CFG->routerconfigured, which
        // is environment-dependent (see hook_callbacks_test's note), so the
        // assertion tolerates that one segment rather than hardcoding a form.
        $this->assertMatchesRegularExpression(
            '#property="og:url" content="https://www\.example\.com/moodle(?:/r\.php)?/local_oerexchange/c/'
                . preg_quote($collection->slug, '#') . '"#',
            $html
        );
        $this->assertStringNotContainsString('name="robots"', $html);
    }

    /**
     * An unlisted collection gets the tags — being shared by link is its
     * whole purpose — plus robots noindex, so following the link does not put
     * it in a search index.
     *
     * @return void
     */
    public function test_an_unlisted_collection_gets_tags_and_noindex(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection((int) $owner->id, 'Shared with my department', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => time(),
            'summary' => 'Readings for the department seminar.',
            'summaryformat' => FORMAT_HTML,
        ]);

        $this->visit_collection($collection->slug);
        $html = $this->head_html();

        $this->assert_full_tag_set($html, 'Shared with my department', 'website');
        $this->assertStringContainsString('name="robots" content="noindex"', $html);
    }

    /**
     * A draft collection emits nothing at all.
     *
     * @return void
     */
    public function test_a_draft_collection_emits_nothing(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection((int) $owner->id, 'Unpublished thoughts');

        $this->visit_collection($collection->slug);

        $this->assertSame('', $this->head_html());
    }

    /**
     * A collection a moderator has taken down emits nothing — not even to a
     * crawler that already had the link.
     *
     * @return void
     */
    public function test_a_held_collection_emits_nothing(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Held reading list', 'A summary.');
        collection_manager::set_modhidden((int) $collection->id, true, 2);

        $this->visit_collection($collection->slug);

        $this->assertSame('', $this->head_html());
    }

    /**
     * A tombstoned collection emits nothing, although its page still renders
     * for everybody.
     *
     * @return void
     */
    public function test_a_tombstoned_collection_emits_nothing(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Deleted reading list', 'A summary.');
        collection_manager::delete((int) $collection->id);

        $this->visit_collection($collection->slug);

        $this->assertSame('', $this->head_html());
    }

    /**
     * A slug that resolves to nothing emits nothing.
     *
     * @return void
     */
    public function test_an_unknown_collection_slug_emits_nothing(): void {
        $this->resetAfterTest();

        $this->visit_collection('no-such-collection');

        $this->assertSame('', $this->head_html());
    }

    /**
     * With collections switched off a collection page describes nothing,
     * because on that site there is no collection page.
     *
     * @return void
     */
    public function test_nothing_is_emitted_when_collections_are_switched_off(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Switched off', 'A summary.');
        set_config('collectionsenabled', 0, 'local_oerexchange');

        $this->visit_collection($collection->slug);

        $this->assertSame('', $this->head_html());
    }

    /**
     * A collection with no description falls back to the generic sentence
     * rather than to an empty preview.
     *
     * @return void
     */
    public function test_a_collection_without_a_description_uses_the_fallback(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'No description yet', '   ');

        $this->visit_collection($collection->slug);
        $html = $this->head_html();

        $this->assertStringContainsString(
            'property="og:description" content="'
                . get_string('collectionognosummary', 'local_oerexchange') . '"',
            $html
        );
    }

    /**
     * A multilang title and description collapse to one language rather than
     * advertising both in every link preview.
     *
     * @return void
     */
    public function test_a_multilang_collection_collapses_to_one_language(): void {
        $this->resetAfterTest();
        // The same trio multilang_rendering_test::enable_multilang() sets.
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection(
            (int) $owner->id,
            '<span lang="en" class="multilang">English title</span>'
                . '<span lang="ja" class="multilang">Japanese title</span>',
            '<p><span lang="en" class="multilang">English summary</span>'
                . '<span lang="ja" class="multilang">Japanese summary</span></p>',
            // A known slug: the generated one would be minted from the
            // multilang markup, and the path gate's charset is the thing
            // under test elsewhere, not here.
            // Nor may the slug itself contain the word the last assertion
            // rules out of the head: og:url carries it.
            ['slug' => 'bilingual-list']
        );

        $this->visit_collection($collection->slug);
        $html = $this->head_html();

        $this->assertStringContainsString('property="og:title" content="English title"', $html);
        $this->assertStringNotContainsString('Japanese title', $html);
        $this->assertStringContainsString('English summary', $html);
        $this->assertStringNotContainsString('Japanese summary', $html);
        $this->assertStringNotContainsString('multilang', $html);
    }

    /**
     * A published resource page gets the full tag set, as og:type article.
     *
     * @return void
     */
    public function test_a_published_resource_page_gets_the_full_tag_set(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();

        $resource = $this->generator()->create_resource([
            'title' => 'Cell biology basics',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);
        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $resource->id,
            'summary' => '<p>An introduction to the cell.</p>',
            'summaryformat' => FORMAT_HTML,
        ]);

        $PAGE->set_url(new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]));
        $html = $this->head_html();

        $this->assert_full_tag_set($html, 'Cell biology basics', 'article');
        $this->assertStringContainsString('An introduction to the cell.', $html);
        $this->assertStringContainsString('resource.php?id=' . $resource->id, $html);
    }

    /**
     * An unpublished resource page emits nothing: the author sees the page,
     * but a crawler is always a stranger.
     *
     * @return void
     */
    public function test_an_unpublished_resource_page_emits_nothing(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();

        $resource = $this->generator()->create_resource([
            'title' => 'Not published yet',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);
        $DB->set_field('local_oerexchange_resources', 'status', 'pending', ['id' => $resource->id]);

        $PAGE->set_url(new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]));

        $this->assertSame('', $this->head_html());
    }

    /**
     * A visible profile page gets the full tag set, as og:type profile.
     *
     * The twitter twins are asserted here too: tests/hook_callbacks_test.php
     * predates them and checks only the og:* half.
     *
     * @return void
     */
    public function test_a_visible_profile_page_gets_the_twitter_tags_too(): void {
        global $PAGE;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'janedoe', 'bio' => 'A biology teacher.', 'expertise' => [], 'visible' => true,
        ]);

        $PAGE->set_url(new \moodle_url('/local_oerexchange/u/janedoe'));
        $html = $this->head_html();

        $this->assert_full_tag_set($html, 'Jane Doe', 'profile');
        $this->assertStringContainsString('A biology teacher.', $html);
        $this->assertStringNotContainsString('name="robots"', $html);
    }

    /**
     * An ordinary page elsewhere on the site stays clean, even when a
     * collection with that slug exists.
     *
     * The path gates are what keep this listener off every other page on the
     * site, and they must not match a course URL that merely ends in
     * something slug-shaped.
     *
     * @return void
     */
    public function test_a_page_that_is_none_of_the_three_emits_nothing(): void {
        global $PAGE;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'A real collection', 'A summary.');

        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => 1, 'slug' => $collection->slug]));

        $this->assertSame('', $this->head_html());
    }
}
