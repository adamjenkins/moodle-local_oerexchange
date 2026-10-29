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
 * Version information for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_oerexchange';
// Bumped for the 1.0.9 release (2026081901, never released standalone,
// registered the rescan_required_plugins_task; this serial just records the
// release string on upgrade — no further schema/services change).
// 2026081903 adds the sandbox settings catalogue, whose upgrade step migrates
// the two retired multilang settings. The release string is deliberately NOT
// bumped with it: that is the owner's call (HARNESS.md §8).
// 2026082200 adds the contributor listing. Its upgrade step backfills
// profile rows for existing co-authors, who are contributors in their own
// right from this version on. The release string is deliberately NOT
// bumped with it: that is the owner's call (HARNESS.md section 8).
// 2026082201 registers the local_oerexchange_get_contributors AJAX
// function. A services.php change only reaches the external_functions
// table when the version serial moves, so adding the entry under the
// previous serial left the endpoint unregistered.
// 2026101900 adds collections: three new tables
// (local_oerexchange_collections, _collection_items, _collection_curators)
// plus a nullable collectionid on _reports and a now-nullable
// _reports.resourceid, so the serial MUST move — a schema change does not
// reach the database without it. It also adds the
// local/oerexchange:createcollection capability and the collections web
// services, both of which are likewise only re-read on a version change:
// db/access.php reaches the capabilities table and db/services.php reaches
// external_functions only when the serial moves, which is exactly what left
// get_contributors unregistered under 2026082200. The release string moves
// to 1.1.0 with it, on the owner's approval (2026-10-19): a minor rather
// than a patch bump, because collections are a new feature with a new
// capability and new schema, not a fix.
// 2026102000 adds resources.collectiononly, registers the collection zip
// tasks (adhoc build, scheduled sweep) and the new settings. Released as
// 1.2.0 on the owner's approval (2026-10-20): a minor bump, because
// collection downloads, collection-only resources, bulk editing, badge
// colours and the share options are new features.
// 2026102001 is the 1.2.1 release: the allowlist rollover moves into an ad
// hoc task (adhoc tasks need no registration), readable badges and warning
// text, upright curator notes and new lang strings. No schema, services or
// capability change; the serial moves so the new strings are re-read.
// 2026102300 is the 1.2.2 release: the whole resource card on a profile page
// links to the resource. Markup only; no schema, strings or capability change.
// 2026102900 adds local_oerexchange_invitations (co-author and curator adds
// become invitations), linkcodes.tokenid (site revoke deletes the personal
// tokens its handshakes produced) and sites.ipkey (per-address registration
// throttle), and registers the cleanup task. Schema change, so the serial
// MUST move; the release string is deliberately NOT bumped with it: that is
// the owner's call (HARNESS.md section 8).
$plugin->version   = 2026102900;
// 2025041400 = the Moodle 5.0 branching version — matches $supported's floor.
// Was 2024100700 (Moodle 4.5), which let a site below the tested/supported
// range install the plugin; found on the fourth MDL Shield audit pass
// (2026-07-19). Eight sibling plugins in this workspace already carry the
// exact same (requires, supported) pair for this range — recounted
// precisely on the independent second pass, 2026-07-19, correcting an
// earlier off-by-one estimate of nine.
$plugin->requires  = 2025041400;
$plugin->supported = [500, 503];
$plugin->release   = '1.2.2';
$plugin->maturity  = MATURITY_STABLE;
