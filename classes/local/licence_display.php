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
 * How a resource's licence shortname is presented.
 *
 * The shortname is stored — and printed — exactly as it was published: core
 * writes every licence shortname lowercase (lib/licenselib.php), and
 * resource_manager::publish() records what the sharer chose without
 * reshaping it. Capitalisation is presentation only, applied by CSS
 * (styles.css) to the class this helper attaches, so the text in the DOM
 * stays the real identifier: a screen reader announces 'cc-sa-4.0' rather
 * than spelling out capitals, a copy-paste yields something that still
 * matches the database, and the catalogue's licence filter keeps comparing
 * like with like.
 *
 * The base class is attached unconditionally so a theme always has something
 * stable to target; only the modifier class is switchable.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class licence_display {
    /** @var string always attached — a theme's stable hook, whatever the setting says */
    const CLASS_BASE = 'oer-licence-name';

    /** @var string attached only while the capitalisation setting is on; styles.css uppercases it */
    const CLASS_UPPER = 'oer-licence-name--upper';

    /**
     * Whether licence shortnames are currently shown in capitals.
     *
     * A setting's declared default is not written to config until the settings
     * page is first saved, so an unset value (false) means "never saved" and
     * must fall back to the default rather than being read as "off" — the same
     * trap allowed_licenses::shortnames() documents for the licence
     * multicheckbox.
     *
     * @return bool
     */
    public static function is_uppercased(): bool {
        $setting = get_config('local_oerexchange', 'uppercaselicencenames');

        return $setting === false ? true : (bool) $setting;
    }

    /**
     * A licence shortname wrapped for display.
     *
     * Returns ESCAPED HTML — callers must not run it through s() again.
     *
     * @param string $shortname licence shortname as stored, e.g. 'cc-sa-4.0'
     * @return string
     */
    public static function html(string $shortname): string {
        $classes = self::CLASS_BASE;
        if (self::is_uppercased()) {
            $classes .= ' ' . self::CLASS_UPPER;
        }

        // Escaped with s(), never format_string(): the shortname is an
        // identifier from the site licence manager, not authored display text.
        // html_writer::span() does not escape its contents (it escapes
        // attributes only), so the s() belongs here.
        return \html_writer::span(s($shortname), $classes);
    }
}
