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

        // The limits the JavaScript needs before it starts an upload: the
        // maximum this site accepts (refusing here saves the user sending
        // hundreds of megabytes only to be rejected on arrival — publish()
        // enforces the same number regardless), and the two sandbox
        // thresholds, which are advisory only.
        $PAGE->requires->js_call_amd('local_oerexchange/upload_progress', 'init', [[
            'maxbytes' => size_advice::max_upload_bytes(),
            'trialwarnbytes' => size_advice::warn_threshold(),
            'trialmaxbytes' => size_advice::max_trial_bytes(),
        ]]);

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

    /**
     * The "maximum accepted size" line, and the region the file-size advice
     * lands in once a file has been chosen.
     *
     * Rendered whether or not JavaScript runs: the limit is worth stating up
     * front either way, and it was previously invisible everywhere — a hidden
     * config an admin could not read and an author could only discover by
     * being rejected after a long upload.
     *
     * @return string HTML to echo under the file input
     */
    public static function size_hint(): string {
        return \html_writer::tag(
            'div',
            get_string('uploadmaxsize', 'local_oerexchange', self::format_limit(size_advice::max_upload_bytes())),
            ['class' => 'small text-muted mb-2']
        ) . \html_writer::tag('div', '', [
            'class' => 'small mb-2',
            'data-region' => 'oerexchange-upload-advice',
            'role' => 'status',
            'aria-live' => 'polite',
        ]);
    }

    /**
     * Human-readable bytes, for a limit shown to a user.
     *
     * @param int $bytes
     * @return string
     */
    private static function format_limit(int $bytes): string {
        return size_advice::format($bytes);
    }
}
