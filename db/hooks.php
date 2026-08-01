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
 * Hook callback registrations for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_oerexchange\hook_callbacks::class . '::before_standard_head_html_generation',
    ],
    // Fires from lib/setup.php:1209 — early enough to serve the catalogue
    // at the site root before core index.php's require_course_login()
    // bounces an anonymous visitor to the login page. Inert unless the
    // publiclanding setting is on; the callback's first test is a plain
    // string compare that rejects every page but the front page.
    [
        'hook'     => \core\hook\after_config::class,
        'callback' => \local_oerexchange\hook_callbacks::class . '::after_config',
    ],
    // Adds the catalogue to core's own "Default home page for users"
    // setting. Covers logged-in users, whom the after_config listener
    // above deliberately leaves alone.
    [
        'hook'     => \core_user\hook\extend_default_homepage::class,
        'callback' => \local_oerexchange\hook_callbacks::class . '::extend_default_homepage',
    ],
];
