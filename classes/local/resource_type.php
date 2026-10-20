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
     * Badge kinds and their default colours: the three resource types plus a
     * collection, which shares a blended catalogue grid with them and so
     * needs to be told apart at a glance.
     *
     * Every default carries white text at a WCAG AA contrast of at least
     * 4.5:1. An admin's own choice gets whichever of black or white text
     * contrasts better (text_colour()), so no colour can make a label
     * unreadable.
     */
    const BADGE_DEFAULTS = [
        'course' => '#0f6cbf',
        'activity' => '#1e7e34',
        'data' => '#a8530a',
        'collection' => '#6f42c1',
    ];

    /**
     * The admin-configured colour for one badge kind, or its default.
     *
     * Validated rather than trusted: the value goes into a style attribute,
     * so anything but a #rgb / #rrggbb hex colour falls back to the default.
     *
     * @param string $kind one of the keys of BADGE_DEFAULTS
     * @return string a #rrggbb colour
     */
    public static function badge_colour(string $kind): string {
        $default = self::BADGE_DEFAULTS[$kind] ?? self::BADGE_DEFAULTS['course'];
        $value = trim((string) get_config('local_oerexchange', 'badgecolour_' . $kind));
        if (preg_match('/^#([0-9a-f]{3}){1,2}$/i', $value)) {
            if (strlen($value) === 4) {
                $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }
            return strtolower($value);
        }
        return $default;
    }

    /**
     * Black or white, whichever contrasts better with a background colour,
     * by WCAG relative luminance.
     *
     * @param string $hex a #rrggbb colour
     * @return string '#000000' or '#ffffff'
     */
    public static function text_colour(string $hex): string {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        // Contrast with white is 1.05 / (L + 0.05), with black (L + 0.05) / 0.05.
        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#ffffff' : '#000000';
    }

    /**
     * A coloured badge.
     *
     * @param string $kind one of the keys of BADGE_DEFAULTS
     * @param string $labelhtml the badge text, already HTML-safe
     * @param string $extraclasses extra classes, e.g. a margin utility
     * @return string HTML
     */
    public static function badge_html(string $kind, string $labelhtml, string $extraclasses = 'me-1'): string {
        $colour = self::badge_colour($kind);
        return \html_writer::tag('span', $labelhtml, [
            'class' => trim('badge oerexchange-badge oerexchange-badge-' . $kind . ' ' . $extraclasses),
            'style' => 'background-color:' . $colour . ';color:' . self::text_colour($colour) . ';',
        ]);
    }

    /**
     * A resource's type as a coloured badge, labelled by label().
     *
     * @param \stdClass $resource a resources row
     * @param string $extraclasses extra classes for the badge
     * @return string HTML
     */
    public static function badge(\stdClass $resource, string $extraclasses = 'me-1'): string {
        $kind = in_array($resource->type ?? '', ['activity', 'data'], true) ? $resource->type : 'course';
        return self::badge_html($kind, self::label($resource), $extraclasses);
    }

    /**
     * The "Collection" badge.
     *
     * @param string $extraclasses extra classes for the badge
     * @return string HTML
     */
    public static function collection_badge(string $extraclasses = 'me-1'): string {
        return self::badge_html('collection', get_string('collection', 'local_oerexchange'), $extraclasses);
    }

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
