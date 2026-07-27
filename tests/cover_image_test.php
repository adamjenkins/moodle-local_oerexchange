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
use local_oerexchange\local\cover_image;

/**
 * Tests for cover_image: the batch URL lookup shared by the catalogue page
 * and the three Exchange blocks, and the two thumbnail shapes it renders.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(cover_image::class)]
final class cover_image_test extends \advanced_testcase {
    /**
     * Store a cover image against a resource id, the way parse_backup_task
     * and resource.php's thumbnail upload both do.
     *
     * @param int $resourceid
     * @param string $filename
     */
    protected function store_cover(int $resourceid, string $filename = 'cover.png'): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'coverimage',
            'itemid' => $resourceid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'not really a png, but the file API does not care');
    }

    public function test_urls_for_returns_only_resources_that_have_an_image(): void {
        $this->resetAfterTest();
        $this->store_cover(11);
        $this->store_cover(13);

        $urls = cover_image::urls_for([11, 12, 13]);

        $this->assertSame([11, 13], array_keys($urls));
        $this->assertStringContainsString('cover.png', $urls[11]->out(false));
        $this->assertStringContainsString(
            '/local_oerexchange/coverimage/11/',
            $urls[11]->out(false),
            'the URL addresses this resource\'s own itemid'
        );
    }

    public function test_urls_for_is_empty_for_no_ids(): void {
        $this->resetAfterTest();
        $this->assertSame([], cover_image::urls_for([]));
    }

    /**
     * The file API stores a '.' entry per directory in every filearea. It is
     * not a file, and building a pluginfile URL for it would produce a link
     * that 404s.
     */
    public function test_urls_for_ignores_the_directory_entry(): void {
        $this->resetAfterTest();
        get_file_storage()->create_directory(
            \context_system::instance()->id,
            'local_oerexchange',
            'coverimage',
            21,
            '/'
        );

        $this->assertSame([], cover_image::urls_for([21]));
    }

    public function test_url_for_single_resource(): void {
        $this->resetAfterTest();
        $this->store_cover(31, 'banner.jpg');

        $this->assertStringContainsString('banner.jpg', cover_image::url_for(31)->out(false));
        $this->assertNull(cover_image::url_for(32));
    }

    /**
     * A resource with no cover still gets a same-sized panel, so a grid of
     * cards keeps its rows aligned instead of stepping up and down.
     */
    public function test_both_shapes_render_a_placeholder_when_there_is_no_image(): void {
        $this->resetAfterTest();

        foreach ([cover_image::card(null), cover_image::listitem(null)] as $html) {
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('oerexchange-thumb-empty', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
        }

        $this->assertStringContainsString((string) cover_image::CARD_HEIGHT . 'px', cover_image::card(null));
        $this->assertStringContainsString((string) cover_image::LIST_SIZE . 'px', cover_image::listitem(null));
    }

    /**
     * Empty alt is deliberate — every call site renders the title as a link
     * next to the image, so alt text would announce the same resource twice.
     */
    public function test_both_shapes_render_an_image_with_empty_alt(): void {
        $this->resetAfterTest();
        $this->store_cover(41);
        $url = cover_image::url_for(41);

        foreach ([cover_image::card($url), cover_image::listitem($url)] as $html) {
            $this->assertStringContainsString('<img', $html);
            $this->assertStringContainsString('alt=""', $html);
            $this->assertStringContainsString('loading="lazy"', $html);
            $this->assertStringContainsString('cover.png', $html);
        }
    }
}
