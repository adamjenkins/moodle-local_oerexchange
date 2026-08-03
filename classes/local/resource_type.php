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
 * The one definition of how a resource's type is named on screen.
 *
 * A resource's `type` column takes three values — course, activity and data —
 * and two of them carry a qualifier: an activity names its module, a data
 * resource names its export kind. Every surface that shows a type badge needs
 * the same sentence, and until this class existed each one built its own.
 *
 * That is what made the profile page wrong. Its badge was a two-way ternary
 * over a three-value column, so 'data' and anything added later both fell
 * through to the 'Course' label, while the catalogue a click away had the
 * correct three-way form. One definition, used everywhere, is the fix.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_type {
    /**
     * The screen label for a resource's type, including its qualifier.
     *
     * Returns HTML-ready text, NOT plain text: the activity's module name is
     * a stored value and is escaped here with s(). Callers put the result
     * straight into an element's content and must not escape it again, which
     * would render a module name containing '&' as '&amp;amp;'.
     *
     * @param \stdClass $resource A resources row; needs type, and
     *                            activitytype/dataresourcetype where relevant.
     * @return string
     */
    public static function label(\stdClass $resource): string {
        $type = $resource->type ?? '';

        if ($type === 'data') {
            $label = get_string('typedata', 'local_oerexchange');
            $kind = $resource->dataresourcetype ?? '';
            // Only the three kinds the parser can produce have a string.
            // An unrecognised value is left unqualified rather than passed to
            // get_string(), which would render a "missing string" placeholder
            // to the visitor for what is really a data problem.
            if ($kind !== '' && in_array($kind, ['glossary', 'questionbank', 'other'], true)) {
                $label .= ' (' . get_string('datatype_' . $kind, 'local_oerexchange') . ')';
            }
            return $label;
        }

        if ($type === 'activity') {
            $label = get_string('typeactivity', 'local_oerexchange');
            $modname = $resource->activitytype ?? '';
            return $modname !== '' ? $label . ' (' . s($modname) . ')' : $label;
        }

        // Course, and — deliberately — anything unrecognised. A catalogue
        // entry always came from somewhere, so a badge is better than a blank.
        return get_string('typecourse', 'local_oerexchange');
    }
}
