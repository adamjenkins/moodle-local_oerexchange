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

namespace local_oerexchange\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Admin-coloured type badges, and the three ways the share buttons can be
 * presented.
 *
 * The badge colour goes into a style attribute, so the tests that matter most
 * are the ones proving a hostile or malformed setting value never reaches it,
 * and that whatever colour an admin picks, the label stays readable.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_type::class)]
#[CoversClass(share_targets::class)]
final class badges_and_sharing_test extends \advanced_testcase {
    /**
     * Unset, each kind has its default; set, the admin's colour is used,
     * with short hex expanded.
     *
     * @return void
     */
    public function test_badge_colours_default_and_follow_the_setting(): void {
        $this->resetAfterTest();

        foreach (resource_type::BADGE_DEFAULTS as $kind => $default) {
            $this->assertSame($default, resource_type::badge_colour($kind));
        }

        set_config('badgecolour_activity', '#FA0', 'local_oerexchange');
        $this->assertSame('#ffaa00', resource_type::badge_colour('activity'));
    }

    /**
     * Anything but a hex colour falls back to the default, so a crafted
     * value can never break out of the style attribute.
     *
     * @return void
     */
    public function test_a_malformed_colour_never_reaches_the_markup(): void {
        $this->resetAfterTest();
        set_config('badgecolour_course', 'red;background:url(x)"><script>', 'local_oerexchange');

        $html = resource_type::badge((object) ['type' => 'course']);

        $this->assertStringContainsString('background-color:' . resource_type::BADGE_DEFAULTS['course'], $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('url(', $html);
    }

    /**
     * Text is white on a dark colour and black on a light one.
     *
     * @return void
     */
    public function test_badge_text_contrasts_with_any_colour(): void {
        $this->assertSame('#ffffff', resource_type::text_colour('#0f6cbf'));
        $this->assertSame('#000000', resource_type::text_colour('#ffe066'));
        $this->assertSame('#000000', resource_type::text_colour('#ffffff'));
        $this->assertSame('#ffffff', resource_type::text_colour('#000000'));
    }

    /**
     * Each type gets its own kind and colour; the label is unchanged.
     *
     * @return void
     */
    public function test_each_type_has_its_own_badge(): void {
        $this->resetAfterTest();

        $activity = resource_type::badge((object) ['type' => 'activity', 'activitytype' => 'quiz']);
        $this->assertStringContainsString('oerexchange-badge-activity', $activity);
        $this->assertStringContainsString(resource_type::BADGE_DEFAULTS['activity'], $activity);
        $this->assertStringContainsString('(quiz)', $activity);

        $data = resource_type::badge((object) ['type' => 'data', 'dataresourcetype' => 'glossary']);
        $this->assertStringContainsString('oerexchange-badge-data', $data);

        $collection = resource_type::collection_badge();
        $this->assertStringContainsString('oerexchange-badge-collection', $collection);
        $this->assertStringContainsString(get_string('collection', 'local_oerexchange'), $collection);
    }

    /**
     * Collapsed (the default) is the original details/summary disclosure.
     *
     * @return void
     */
    public function test_share_collapsed_is_the_default_disclosure(): void {
        $this->resetAfterTest();

        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');

        $this->assertSame(share_targets::DISPLAY_COLLAPSED, share_targets::display());
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    /**
     * Open shows the same panel with no disclosure, still wired for share.js.
     *
     * @return void
     */
    public function test_share_open_shows_the_buttons_without_collapsing(): void {
        $this->resetAfterTest();
        set_config('sharedisplay', share_targets::DISPLAY_OPEN, 'local_oerexchange');

        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');

        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringContainsString('data-region="oerexchange-share"', $html);
        $this->assertStringContainsString('oerexchange-share-target-facebook', $html);
        $this->assertStringContainsString('Share this resource', $html);
    }

    /**
     * Hidden shows no share buttons, but keeps "Add to collection".
     *
     * @return void
     */
    public function test_share_hidden_keeps_the_add_to_collection_control(): void {
        $this->resetAfterTest();
        set_config('sharedisplay', share_targets::DISPLAY_HIDDEN, 'local_oerexchange');

        $this->assertSame('', share_targets::render('https://example.com/r', 'A resource', 'Share this resource'));

        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource', '<form>add</form>');
        $this->assertStringContainsString('<form>add</form>', $html);
        $this->assertStringNotContainsString('oerexchange-share-target', $html);
    }

    /**
     * Every target carries its own class, which styles.css colours.
     *
     * @return void
     */
    public function test_each_share_target_carries_its_brand_class(): void {
        $this->resetAfterTest();

        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');

        foreach (share_targets::ALL as $key) {
            $this->assertStringContainsString('oerexchange-share-target-' . $key, $html);
        }
        $this->assertStringNotContainsString('btn-outline-secondary btn-sm', $html, 'the plain grey buttons are gone');
    }

    /**
     * A Mastodon server is a bare domain name, nothing else.
     *
     * @return void
     */
    public function test_mastodon_instance_validation(): void {
        foreach (['mastodon.social', 'fosstodon.org', 'social.example.co.uk'] as $ok) {
            $this->assertTrue(share_targets::valid_mastodon_instance($ok), $ok);
        }
        foreach (['', 'https://mastodon.social', 'mastodon', 'evil.com/path', 'a b.com', 'javascript:alert(1)'] as $bad) {
            $this->assertFalse(share_targets::valid_mastodon_instance($bad), $bad);
        }
    }

    /**
     * The preference can only be set by its own user, and anything that is
     * not a bare domain is cleaned to '' rather than stored.
     *
     * @return void
     */
    public function test_the_mastodon_preference_is_the_users_own_and_cleaned(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(\core_user::can_edit_preference(share_targets::MASTODON_PREFERENCE, $user));
        $this->assertFalse(\core_user::can_edit_preference(share_targets::MASTODON_PREFERENCE, $other));

        $this->assertSame('fosstodon.org', \core_user::clean_preference('Fosstodon.org', share_targets::MASTODON_PREFERENCE));
        $this->assertSame('', \core_user::clean_preference('https://evil.example/x', share_targets::MASTODON_PREFERENCE));
    }

    /**
     * With a saved server the button names it, the share goes there without
     * asking, and "Change server" is offered; without one, neither.
     *
     * @return void
     */
    public function test_the_button_names_a_saved_server(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');
        $this->assertStringContainsString('data-mastodon-instance=""', $html);
        $this->assertStringContainsString('data-mastodon-save="1"', $html);
        $this->assertMatchesRegularExpression('/data-action="oerexchange-share-mastodon-change" hidden="hidden"/', $html);

        set_user_preference(share_targets::MASTODON_PREFERENCE, 'fosstodon.org', $user);
        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');
        $this->assertStringContainsString('data-mastodon-instance="fosstodon.org"', $html);
        $this->assertStringContainsString(get_string('sharetarget_mastodon_at', 'local_oerexchange', 'fosstodon.org'), $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="oerexchange-share-mastodon-change" hidden/', $html);
    }

    /**
     * A visitor has no account to save to, and a tampered preference value
     * is never rendered.
     *
     * @return void
     */
    public function test_visitors_and_tampered_values(): void {
        $this->resetAfterTest();

        $this->setUser(null);
        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');
        $this->assertStringContainsString('data-mastodon-save="0"', $html);

        // A visitor's browser remembers through its cookie, which is
        // validated like the preference: a tampered one is ignored.
        $_COOKIE[share_targets::MASTODON_COOKIE] = 'Mastodon.Social';
        $this->assertSame('mastodon.social', share_targets::mastodon_instance(0));
        $_COOKIE[share_targets::MASTODON_COOKIE] = '"><script>x</script>';
        $this->assertSame('', share_targets::mastodon_instance(0));
        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');
        $this->assertStringNotContainsString('<script>x', $html);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_user_preference(share_targets::MASTODON_PREFERENCE, '"><script>x</script>', $user);
        $this->assertSame('', share_targets::mastodon_instance((int) $user->id));
        $html = share_targets::render('https://example.com/r', 'A resource', 'Share this resource');
        $this->assertStringNotContainsString('<script>x', $html);

        // The account's choice beats the browser's.
        $_COOKIE[share_targets::MASTODON_COOKIE] = 'mastodon.social';
        set_user_preference(share_targets::MASTODON_PREFERENCE, 'fosstodon.org', $user);
        $this->assertSame('fosstodon.org', share_targets::mastodon_instance((int) $user->id));
        unset($_COOKIE[share_targets::MASTODON_COOKIE]);
    }

    /**
     * The saved server is exported with the user's other preferences.
     *
     * @return void
     */
    public function test_the_mastodon_preference_is_exported(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference(share_targets::MASTODON_PREFERENCE, 'fosstodon.org', $user);

        \local_oerexchange\privacy\provider::export_user_preferences((int) $user->id);

        $prefs = \core_privacy\local\request\writer::with_context(\context_system::instance())
            ->get_user_preferences('local_oerexchange');
        $this->assertSame('fosstodon.org', $prefs->{share_targets::MASTODON_PREFERENCE}->value);
    }
}
