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

/**
 * Tests for the path_profileslug route parameter.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange\router\parameters;

use core\param;
use core\tests\router\route_testcase;
use invalid_parameter_exception;

/**
 * Tests for the path_profileslug route parameter.
 *
 * The class under test is a thin schema/validation-metadata wrapper (mirrors core's
 * path_themename), so these tests exercise the charset validation it declares rather than
 * any bespoke behaviour of its own.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\router\parameters\path_profileslug
 */
final class path_profileslug_test extends route_testcase {
    public function test_default_name_and_type(): void {
        $param = new path_profileslug();
        $this->assertEquals('slug', $param->get_name());
        $this->assertEquals('path', $param->get_in());
        $this->assertEquals(param::ALPHANUMEXT, $param->get_type());
    }

    public function test_custom_name(): void {
        $param = new path_profileslug(name: 'handle');
        $this->assertEquals('handle', $param->get_name());
    }

    /**
     * Valid slugs (matching the ALPHANUMEXT charset used consistently by
     * profile_manager::is_valid_slug()) must pass validation unchanged.
     *
     * @dataProvider valid_slug_provider
     * @param string $slug
     */
    public function test_validate_accepts_valid_slug(string $slug): void {
        $param = new path_profileslug();

        $request = $this->create_route('/u/{slug}', "/u/{$slug}");
        $route = $this->get_slim_route_from_request($request);

        $result = $param->validate($request, $route);
        $this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $result);
    }

    /**
     * Data provider for valid slugs, including boundary lengths.
     *
     * @return array
     */
    public static function valid_slug_provider(): array {
        return [
            'simple lowercase' => ['janedoe'],
            'with hyphen' => ['jane-doe'],
            'with underscore' => ['jane_doe'],
            'alphanumeric' => ['jane123'],
            'single character (minimum length)' => ['a'],
            'long slug (100 characters)' => [str_repeat('a', 100)],
        ];
    }

    /**
     * Slugs containing characters outside the ALPHANUMEXT charset must be rejected.
     *
     * @dataProvider invalid_slug_provider
     * @param string $slug
     */
    public function test_validate_rejects_invalid_characters(string $slug): void {
        $param = new path_profileslug();

        $request = $this->create_route('/u/{slug}', '/u/' . rawurlencode($slug));
        $route = $this->get_slim_route_from_request($request);

        $this->expectException(invalid_parameter_exception::class);
        $param->validate($request, $route);
    }

    /**
     * Data provider for slugs which must fail validation.
     *
     * @return array
     */
    public static function invalid_slug_provider(): array {
        return [
            'space' => ['jane doe'],
            'dot' => ['jane.doe'],
            'at sign' => ['jane@doe'],
            'unicode' => ['jané'],
        ];
    }
}
