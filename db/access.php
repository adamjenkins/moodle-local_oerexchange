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
 * Capability definitions for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Creating a collection is the one thing an ordinary signed-in user does
    // here that this plugin gates by capability rather than by ownership, so
    // that a site which does not want everybody minting public pages can take
    // it away from the authenticated-user role without touching anything
    // else. 'user' => CAP_ALLOW is the default because the feature is for
    // educators, not administrators, and a collection nobody can create is
    // not a feature.
    //
    // RISK_SPAM, not RISK_XSS: a collection carries a title, a summary and
    // per-item notes that become publicly visible, which is spam-shaped
    // (moderate.php and the modhidden takedown exist for it). It is not
    // RISK_XSS because none of that text is ever trusted — every sink filters
    // or escapes it (format_string(), format_text(), s()), exactly as
    // resource titles and summaries are. RISK_PERSONAL does not apply either:
    // a collection holds no personal data about anyone but its own
    // contributors, who put themselves there.
    'local/oerexchange:createcollection' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
    'local/oerexchange:moderate' => [
        'riskbitmask' => RISK_SPAM | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/oerexchange:managesandbox' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/oerexchange:managesites' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
