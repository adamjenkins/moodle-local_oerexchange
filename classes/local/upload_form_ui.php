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
 * The progress region shared by both upload forms.
 *
 * Rendered hidden and left that way unless the JavaScript takes over the
 * submit, so a browser with JavaScript disabled shows an ordinary form with
 * no empty progress furniture below it.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form_ui {
    /**
     * Markup for the progress bar, and the AMD module that drives it.
     *
     * @return string HTML to echo inside the form
     */
    public static function progress_region(): string {
        global $PAGE;

        $PAGE->requires->js_call_amd('local_oerexchange/upload_progress', 'init');

        $bar = \html_writer::tag('div', '', [
            'class' => 'progress-bar',
            'style' => 'width: 0%;',
            // Announced as a progress bar rather than a decorative stripe;
            // the module keeps aria-valuenow in step with the width and drops
            // it while the stage is indeterminate.
            'role' => 'progressbar',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
            'aria-valuenow' => '0',
            'aria-label' => get_string('uploadstarting', 'local_oerexchange'),
        ]);

        return \html_writer::tag(
            'div',
            \html_writer::tag('div', $bar, ['class' => 'progress mb-1', 'style' => 'height: 1rem;'])
                . \html_writer::tag('div', '', [
                    'class' => 'small text-muted',
                    'data-region' => 'oerexchange-upload-status',
                    // The percentage is announced politely as it changes; a
                    // 359 MB upload otherwise tells a screen-reader user
                    // nothing between "submit" and "done".
                    'role' => 'status',
                    'aria-live' => 'polite',
                ]),
            ['class' => 'd-none mt-2', 'data-region' => 'oerexchange-upload-progress']
        );
    }
}
