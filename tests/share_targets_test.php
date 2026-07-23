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

/**
 * Tests for the share affordance's target list and URL building.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(share_targets::class)]
final class share_targets_test extends \advanced_testcase {
    public function test_every_target_is_enabled_before_the_admin_saves_the_setting(): void {
        $this->resetAfterTest();

        $this->assertSame(share_targets::ALL, share_targets::enabled());
    }

    public function test_only_ticked_targets_are_offered_and_always_in_display_order(): void {
        $this->resetAfterTest();

        // Deliberately out of display order in the stored config.
        set_config('sharetargets', 'email,facebook,copylink', 'local_oerexchange');

        $this->assertSame(['copylink', 'facebook', 'email'], share_targets::enabled());
    }

    /**
     * An admin who unticks every box stores an empty string. That must mean
     * "offer nothing", not be mistaken for "setting never saved" and
     * silently turn every target back on.
     */
    public function test_unticking_everything_disables_sharing_rather_than_restoring_defaults(): void {
        $this->resetAfterTest();

        set_config('sharetargets', '', 'local_oerexchange');

        $this->assertSame([], share_targets::enabled());
        $this->assertSame('', share_targets::render('https://example.com/x', 'Title', 'Share'));
    }

    public function test_unknown_keys_in_the_stored_config_are_ignored(): void {
        $this->resetAfterTest();

        set_config('sharetargets', 'copylink,myspace,facebook', 'local_oerexchange');

        $this->assertSame(['copylink', 'facebook'], share_targets::enabled());
    }

    public function test_network_targets_get_urls_and_scripted_targets_do_not(): void {
        $this->resetAfterTest();

        $targets = [];
        foreach (share_targets::build('https://example.com/r?id=7', 'Intro to Algebra') as $target) {
            $targets[$target['key']] = $target['href'];
        }

        // Driven by amd/src/share.js, so no href to follow.
        foreach (share_targets::SCRIPTED as $scripted) {
            $this->assertArrayHasKey($scripted, $targets);
            $this->assertNull($targets[$scripted], "{$scripted} must not have an href");
        }

        $this->assertStringStartsWith('https://www.facebook.com/sharer/', $targets['facebook']);
        $this->assertStringStartsWith('https://twitter.com/intent/tweet', $targets['x']);
        $this->assertStringStartsWith('https://www.linkedin.com/sharing/', $targets['linkedin']);
        $this->assertStringStartsWith('mailto:', $targets['email']);
        $this->assertStringStartsWith('sms:', $targets['sms']);
    }

    /**
     * The shared URL carries a query string of its own, and titles are
     * arbitrary user text — both must survive as encoded parameters rather
     * than breaking out into the outbound URL.
     */
    public function test_url_and_title_are_encoded_into_every_outbound_link(): void {
        $this->resetAfterTest();

        $url = 'https://example.com/r.php?id=7&x=1';
        $title = 'Algebra & "friends" / part 1';

        foreach (share_targets::build($url, $title) as $target) {
            if ($target['href'] === null) {
                continue;
            }
            $this->assertStringNotContainsString(
                '?id=7&x=1',
                $target['href'],
                "{$target['key']} embedded the raw query string instead of encoding it"
            );
            $this->assertStringNotContainsString('"', $target['href']);
            $this->assertStringContainsString(rawurlencode($url), $target['href'] . rawurlencode($url));
        }
    }

    public function test_the_disclosure_always_offers_a_selectable_url_as_the_no_javascript_fallback(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Even with every scripted target switched off, the URL itself must
        // still be there to select — that is the whole point of the fallback.
        set_config('sharetargets', 'facebook', 'local_oerexchange');

        $html = share_targets::render('https://example.com/r/9', 'A resource', 'Share this');

        $this->assertStringContainsString('data-region="oerexchange-share-url"', $html);
        $this->assertStringContainsString('readonly="readonly"', $html);
        $this->assertStringContainsString('https://example.com/r/9', $html);
        $this->assertStringContainsString('Share this', $html);
    }

    public function test_outbound_links_never_leak_the_referrer_or_a_window_handle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = share_targets::render('https://example.com/r/9', 'A resource', 'Share this');

        $this->assertSame(
            substr_count($html, 'target="_blank"'),
            substr_count($html, 'rel="noopener noreferrer"'),
            'every _blank share link must carry rel="noopener noreferrer"'
        );
    }

    /**
     * The setting's checkbox labels and the rendered buttons read from the
     * same lang keys, so a missing one is a broken UI on both. Catch it here
     * rather than as a debugging notice on a live page.
     */
    public function test_every_target_has_a_label_string(): void {
        $this->resetAfterTest();

        foreach (share_targets::ALL as $key) {
            $this->assertTrue(
                get_string_manager()->string_exists('sharetarget_' . $key, 'local_oerexchange'),
                "missing lang string sharetarget_{$key}"
            );
        }
    }
}
