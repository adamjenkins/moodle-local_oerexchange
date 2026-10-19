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
 * Message providers for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'import' => [],
    'review' => [],
    'report' => [],
    'stale' => [],
    'coauthor' => [],
    // Being handed curating rights over somebody else's published list
    // without being told would be worse than noisy — sent by
    // collection_curator_manager::notify_added().
    'collectioncurator' => [],
    // Tells a collection's owner that somebody ELSE put a resource in their
    // collection: a named curator, or anyone at all while open curation is
    // on. The owner's own additions never notify — they did it.
    'collectionitem' => [],
];
