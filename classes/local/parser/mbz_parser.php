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

namespace local_oerexchange\local\parser;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Thin wrapper over core's backup_general_helper::get_backup_information_from_mbz(),
 * producing a structure-preview JSON tree and a required-plugins list.
 *
 * See DESIGN.md §2 (mbz_parser) — validated against Moodle 5.2.1 source:
 * backup/moodle2/backup_stepslib.php's contents manifest gives each activity
 * {moduleid, sectionid, modulename, title, directory, insubsection} and each
 * section {sectionid, title, directory, parentcmid, modname}.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mbz_parser {
    /**
     * Parse an .mbz file into a structure preview + required-plugins list.
     *
     * @param string $filepath absolute path to the .mbz file
     * @return \stdClass {moodleversion, backupversion, type, courseformat, structurejson (string), requiredplugins (array)}
     * @throws \backup_helper_exception on a malformed/unreadable backup
     */
    public static function parse(string $filepath): \stdClass {
        $info = \backup_general_helper::get_backup_information_from_mbz($filepath);

        $sections = [];
        foreach ($info->sections as $section) {
            $sections[$section->sectionid] = [
                'sectionid' => (int) $section->sectionid,
                'title' => $section->title,
                'activities' => [],
            ];
        }

        foreach ($info->activities as $activity) {
            $sectionid = (int) $activity->sectionid;
            if (!isset($sections[$sectionid])) {
                // Orphaned activity (shouldn't normally happen) — bucket it separately.
                $sections[$sectionid] = [
                    'sectionid' => $sectionid,
                    'title' => null,
                    'activities' => [],
                ];
            }
            $sections[$sectionid]['activities'][] = [
                'modulename' => $activity->modulename,
                'title' => $activity->title,
            ];
        }

        $courseformat = $info->original_course_format ?? '';

        $structure = [
            'coursetitle' => $info->course->title ?? null,
            'courseformat' => $courseformat,
            'type' => $info->type ?? null,
            'sections' => array_values($sections),
        ];

        return (object) [
            'moodleversion' => (string) ($info->moodle_release ?? $info->moodle_version ?? ''),
            'backupversion' => (string) ($info->backup_release ?? $info->backup_version ?? ''),
            'type' => $info->type ?? null,
            'courseformat' => $courseformat,
            'structurejson' => json_encode($structure),
            'requiredplugins' => self::derive_required_plugins($info, $courseformat, $filepath),
        ];
    }

    /**
     * Extract the course's cover image — the file stored via the File API as
     * component 'course', filearea 'overviewfiles' (the "course image" shown
     * on course cards; see course_overviewfiles_options() in core) — from an
     * .mbz backup.
     *
     * get_backup_information_from_mbz() (used by parse()) only ever extracts
     * moodle_backup.xml and carries no file information, so this reads the
     * backup's own files.xml manifest directly to locate the content-addressed
     * file, then extracts just that one file from the archive.
     *
     * @param string $filepath absolute path to the .mbz file
     * @param string $outputdir writable directory to extract into (the caller
     *                          owns cleanup of this directory)
     * @return string|null absolute path to the extracted image (original
     *                     filename preserved), or null if the backup has no
     *                     course overview image
     */
    public static function extract_cover_image(string $filepath, string $outputdir): ?string {
        $packer = get_file_packer('application/vnd.moodle.backup');

        $manifestdir = $outputdir . '/coverimage_manifest';
        check_dir_exists($manifestdir);
        $packer->extract_to_pathname($filepath, $manifestdir, ['files.xml']);

        $manifestpath = $manifestdir . '/files.xml';
        if (!is_readable($manifestpath)) {
            return null;
        }

        $xml = simplexml_load_file($manifestpath);
        if ($xml === false) {
            return null;
        }

        foreach ($xml->file as $filenode) {
            if ((string) $filenode->component !== 'course' || (string) $filenode->filearea !== 'overviewfiles') {
                continue;
            }

            $filename = (string) $filenode->filename;
            $contenthash = (string) $filenode->contenthash;
            if ($filename === '.' || $filename === '' || $contenthash === '') {
                // A "." filename is files.xml's directory-placeholder entry, not a real file.
                continue;
            }

            if (!self::is_allowed_cover_image_type($filename)) {
                // The manifest entry's claimed filename/mimetype is attacker-influenced (it
                // comes straight from an uploaded .mbz), so it gets no more trust here than
                // resource.php's direct thumbnail-upload path gives a draft file — reject
                // anything outside an explicit raster-image allowlist rather than storing it.
                // This is not an XSS mitigation (local_oerexchange_pluginfile() never passes
                // dontforcesvgdownload, so core's send_file() already forces image/svg+xml
                // downloads — see lib.php); it exists because nothing on this path should have
                // weaker input validation than the sibling upload path already has.
                continue;
            }

            $archivepath = 'files/' . substr($contenthash, 0, 2) . '/' . $contenthash;
            $contentdir = $outputdir . '/coverimage_content';
            check_dir_exists($contentdir);
            $packer->extract_to_pathname($filepath, $contentdir, [$archivepath]);

            $extracted = $contentdir . '/' . $archivepath;
            if (!is_readable($extracted)) {
                continue;
            }

            if (!self::is_verified_raster_image($extracted)) {
                // The extension/mimetype allowlist above only checked the
                // manifest's claimed filename — also attacker-influenced.
                // Verify the actual bytes: getimagesize() reports the real
                // detected image type regardless of what the manifest or
                // filename claimed, catching e.g. arbitrary content renamed
                // to a '.png' filename that passed is_allowed_cover_image_type().
                continue;
            }

            $target = $contentdir . '/' . clean_param($filename, PARAM_FILE);
            if ($target !== $extracted && @rename($extracted, $target)) {
                return $target;
            }
            return $extracted;
        }

        return null;
    }

    /**
     * Whether a manifest entry's filename resolves (via core's extension-based
     * mimeinfo()) to one of the raster image formats this plugin accepts as a
     * course cover image.
     *
     * Deliberately an explicit allowlist, not an "image/" prefix check: core
     * maps a '.svg' filename to the mimetype 'image/svg+xml', which begins
     * with 'image/' but is not a raster format — and unlike a prefix check,
     * an allowlist can't accidentally admit it or any other non-raster
     * "image/*" type.
     *
     * @param string $filename
     * @return bool
     */
    protected static function is_allowed_cover_image_type(string $filename): bool {
        static $allowedmimetypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        return in_array(mimeinfo('type', $filename), $allowedmimetypes, true);
    }

    /**
     * Whether a file on disk is actually one of the raster image formats this
     * plugin accepts as a course cover image, verified from its real bytes
     * rather than its claimed filename/mimetype (MDL Shield round 2 audit
     * finding 2, 2026-07-19). is_allowed_cover_image_type() above only checks
     * the manifest's claimed filename, which — like the filename itself — is
     * attacker-influenced (it comes straight from an uploaded .mbz); content
     * naming itself 'cover.png' would pass that check regardless of what it
     * actually contains. getimagesize() sniffs the real bytes: it returns
     * false for non-image content, and reports the ACTUAL detected type (its
     * [2] element, an IMAGETYPE_* constant) regardless of the file's claimed
     * extension. Applied as an ADDITIONAL check alongside (not instead of)
     * is_allowed_cover_image_type() — same accepted raster set, kept
     * consistent with resource.php's equivalent check.
     *
     * @param string $path absolute path to a file already extracted to disk
     * @return bool
     */
    protected static function is_verified_raster_image(string $path): bool {
        static $allowedimagetypes = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        $imageinfo = @getimagesize($path);
        if ($imageinfo === false) {
            return false;
        }
        return in_array($imageinfo[2], $allowedimagetypes, true);
    }

    /**
     * Ceiling on the stored required-plugins list. The list is derived from
     * attacker-supplied archive content (a crafted backup can declare
     * thousands of fake plugin names), so it must not grow the DB row without
     * bound; a backup near this many missing plugins is unusable anyway.
     */
    const MAX_REQUIRED_PLUGINS = 100;

    /**
     * Ceiling on archive members the deep scan will extract in one parse.
     * Every member is attacker-influence-able (block directories especially
     * are enumerated straight from the listing), and each wanted member
     * becomes a temp file — a crafted archive with 100k block.xml members
     * must not become 100k extractions. Real course backups sit far below
     * this.
     */
    const SCAN_MEMBER_LIMIT = 500;

    /**
     * Connection points each standard mod attaches subplugin structures at —
     * the last token of a subplugin_<type>_<name>_<connectionpoint> element
     * name. Closed per-mod sets read from the mods' backup stepslibs (Moodle
     * 5.1 + 5.2.1; assign's unconditional 'assign' point is 5.2+, its
     * 'submission'/'grade' points exist only in userinfo backups). Mods
     * absent here (data, forum, book, scorm) declare subplugin types but
     * never back them up; a future core version adding one falls back to
     * the last-token rule in split_subplugin_element().
     */
    protected const SUBPLUGIN_CONNECTION_POINTS = [
        'assign' => ['submission', 'grade', 'assign'],
        'quiz' => ['quiz', 'attempt'],
        'workshop' => ['workshop', 'referenceassessment', 'exampleassessment', 'assessment'],
        'lti' => ['lti'],
        'bigbluebuttonbn' => ['bigbluebuttonbn'],
    ];

    /**
     * Compare the backup's declared and embedded components against Moodle's
     * shipped standard-plugins list for this site's Moodle version, to find
     * non-core plugins the backup depends on.
     *
     * Two layers, merged and de-duplicated:
     * - the moodle_backup.xml contents manifest: activity modules + course
     *   format (the original V1 scope);
     * - the archive itself, via scan_archive_plugins(): question types
     *   (questions.xml), question behaviours (quiz.xml preferredbehaviour),
     *   mod subplugins (subplugin_* elements in each activity's XML),
     *   advanced-grading methods (grading.xml) and blocks (block
     *   directories) — everything restore would silently drop or crash on
     *   when the plugin is missing, per the 2026-08-19 plugin-type gap
     *   audit (dev-docs/oer-platform/PLUGINTYPE-GAP-AUDIT-2026-08-19.md).
     *
     * @param \stdClass $info parsed moodle_backup.xml information
     * @param string $courseformat the backup's course format
     * @param string $filepath absolute path to the .mbz file itself
     * @return array list of ['type' => string, 'name' => string]
     */
    protected static function derive_required_plugins(\stdClass $info, string $courseformat, string $filepath): array {
        $standardmods = \core\plugin_manager::standard_plugins_list('mod') ?: [];
        $standardformats = \core\plugin_manager::standard_plugins_list('format') ?: [];

        $required = [];
        $seen = [];
        $add = function (string $type, string $name) use (&$required, &$seen): void {
            $key = $type . ':' . $name;
            if (isset($seen[$key]) || count($required) >= self::MAX_REQUIRED_PLUGINS) {
                return;
            }
            // DELETED standard plugins (qtype_random above all — near-universal
            // in pre-4.0 quiz backups, and a name core forbids any plugin from
            // ever using; also the blocks retired from core over the years) are
            // handled by core's restore itself, so they are not a dependency:
            // reporting one would show a permanently-unmeetable "missing
            // plugin" badge and put an uninstallable name on the sandbox
            // blueprint's install list.
            if (\core\plugin_manager::is_deleted_standard_plugin($type, $name)) {
                return;
            }
            $seen[$key] = true;
            $required[] = ['type' => $type, 'name' => $name];
        };

        foreach ($info->activities as $activity) {
            $modname = $activity->modulename;
            if (!$modname || in_array($modname, $standardmods, true)) {
                continue;
            }
            $add('mod', $modname);
        }

        if ($courseformat !== '' && !in_array($courseformat, $standardformats, true)) {
            $add('format', $courseformat);
        }

        foreach (self::scan_archive_plugins($filepath, $info, $standardmods) as $plugin) {
            $add($plugin['type'], $plugin['name']);
        }

        return $required;
    }

    /**
     * Scan the archive's own members for non-standard plugin dependencies
     * that the moodle_backup.xml contents manifest does not declare.
     *
     * One list_files() pass finds which members exist, then one batched
     * extract_to_pathname() call pulls exactly those members (both are
     * single passes over the archive; get_file_packer with the .mbz
     * mimetype returns core's mbz_packer, which handles tgz- and
     * zip-format backups transparently for both operations).
     *
     * A member that exists but cannot be read as XML is skipped rather than
     * failing the parse: the moodle_backup.xml parse in parse() has already
     * gated overall validity, and a malformed optional member should not
     * take down an upload that core's own restore would still attempt.
     *
     * @param string $filepath absolute path to the .mbz file
     * @param \stdClass $info parsed moodle_backup.xml information
     * @param array $standardmods standard 'mod' plugin names
     * @return array list of ['type' => string, 'name' => string]
     */
    protected static function scan_archive_plugins(string $filepath, \stdClass $info, array $standardmods): array {
        $packer = get_file_packer('application/vnd.moodle.backup');

        $listing = $packer->list_files($filepath);
        if (!is_array($listing)) {
            return [];
        }
        $members = [];
        foreach ($listing as $entry) {
            if (empty($entry->is_directory)) {
                $members[$entry->pathname] = true;
            }
        }

        // Decide which members to pull. Activity directories come from the
        // already-parsed manifest, but the directory string is
        // attacker-supplied, so it is only used when it has exactly the
        // shape core's backup writes (activities/<mod>_<moduleid>).
        $wanted = [];
        $activityxmls = [];
        $gradingxmls = [];
        if (isset($members['questions.xml'])) {
            $wanted[] = 'questions.xml';
        }
        foreach ($info->activities as $activity) {
            if (count($wanted) >= self::SCAN_MEMBER_LIMIT) {
                break;
            }
            $modname = (string) ($activity->modulename ?? '');
            $directory = (string) ($activity->directory ?? '');
            if (!preg_match('#^activities/[a-z][a-z0-9_]*_\d+$#', $directory)) {
                continue;
            }
            // The grading.xml first: core writes it for ANY module declaring
            // FEATURE_ADVANCED_GRADING, third-party mods included, and a
            // gradingform plugin is an independent top-level dependency —
            // installing the mod alone would still leave its rubric/marking
            // guide unrestorable.
            $gradingpath = $directory . '/grading.xml';
            if (isset($members[$gradingpath]) && !in_array($gradingpath, $gradingxmls, true)) {
                $wanted[] = $gradingpath;
                $gradingxmls[] = $gradingpath;
            }
            // The activity's own XML is scanned for standard mods only:
            // subplugins of a non-standard mod are implied by the mod entry
            // itself (and could not be classified against a standard list).
            if (!in_array($modname, $standardmods, true)) {
                continue;
            }
            $xmlpath = $directory . '/' . $modname . '.xml';
            if (isset($members[$xmlpath]) && !isset($activityxmls[$xmlpath])) {
                $wanted[] = $xmlpath;
                $activityxmls[$xmlpath] = $modname;
            }
        }
        // Blocks exist ONLY as archive directories — they are absent from
        // the contents manifest — so candidates come from the listing. The
        // directory name is <blockname>_<blockinstanceid>; the id suffix is
        // always pure digits, so stripping the final _<digits> segment
        // recovers the name, and block.xml is only extracted for
        // non-standard candidates (its <blockname> child is authoritative).
        $standardblocks = \core\plugin_manager::standard_plugins_list('block') ?: [];
        $blockxmls = [];
        foreach ($members as $path => $unused) {
            if (count($wanted) >= self::SCAN_MEMBER_LIMIT) {
                // A real backup never has this many interesting members; a
                // crafted one must not turn the listing into an unbounded
                // temp-file extraction.
                break;
            }
            if (!preg_match('#^(?:course|activities/[^/]+)/blocks/(.+)_\d+/block\.xml$#', $path, $matches)) {
                continue;
            }
            $candidate = $matches[1];
            if (in_array($candidate, $standardblocks, true)) {
                continue;
            }
            $wanted[] = $path;
            $blockxmls[$path] = $candidate;
        }

        if (!$wanted) {
            return [];
        }

        $scandir = make_temp_directory('oerexchange/pluginscan_' . md5($filepath . '|' . microtime()));
        try {
            $packer->extract_to_pathname($filepath, $scandir, $wanted);

            $found = [];

            // Question types: <qtype> is a child element of <question> in
            // every era of questions.xml (modern bank-entry structure and
            // the pre-4.0 legacy shape alike), and no other element in
            // questions.xml carries that name.
            foreach (self::collect_element_texts($scandir . '/questions.xml', ['qtype']) as $qtype) {
                if (
                    self::is_valid_detected_name($qtype)
                        && !in_array($qtype, \core\plugin_manager::standard_plugins_list('qtype') ?: [], true)
                ) {
                    $found[] = ['type' => 'qtype', 'name' => $qtype];
                }
            }

            // Mod subplugins (+ quiz's question behaviour setting).
            foreach ($activityxmls as $xmlpath => $modname) {
                foreach (self::collect_subplugin_element_names($scandir . '/' . $xmlpath) as $element) {
                    $split = self::split_subplugin_element($element, $modname);
                    if ($split === null) {
                        continue;
                    }
                    [$subtype, $subname] = $split;
                    if (!in_array($subname, \core\plugin_manager::standard_plugins_list($subtype) ?: [], true)) {
                        $found[] = ['type' => $subtype, 'name' => $subname];
                    }
                }
                if ($modname === 'quiz') {
                    foreach (self::collect_element_texts($scandir . '/' . $xmlpath, ['preferredbehaviour']) as $behaviour) {
                        if (
                            self::is_valid_detected_name($behaviour)
                                && !in_array($behaviour, \core\plugin_manager::standard_plugins_list('qbehaviour') ?: [], true)
                        ) {
                            $found[] = ['type' => 'qbehaviour', 'name' => $behaviour];
                        }
                    }
                }
            }

            // Advanced-grading methods: grading.xml's
            // areas > area > definitions > definition > <method> names the
            // gradingform plugin of each stored definition ('method' appears
            // nowhere else in the file; the active-but-undefined method
            // element is named 'activemethod' and carries no form data).
            foreach ($gradingxmls as $gradingpath) {
                foreach (self::collect_element_texts($scandir . '/' . $gradingpath, ['method']) as $method) {
                    if (
                        self::is_valid_detected_name($method)
                            && !in_array($method, \core\plugin_manager::standard_plugins_list('gradingform') ?: [], true)
                    ) {
                        $found[] = ['type' => 'gradingform', 'name' => $method];
                    }
                }
            }

            // Blocks: block.xml's <blockname> child is written from the same
            // cleaned value as the directory name; trust it when readable,
            // fall back to the directory-derived candidate.
            foreach ($blockxmls as $path => $candidate) {
                $blockname = $candidate;
                $texts = self::collect_element_texts($scandir . '/' . $path, ['blockname']);
                if ($texts !== [] && self::is_valid_detected_name($texts[0])) {
                    $blockname = $texts[0];
                }
                if (
                    self::is_valid_detected_name($blockname)
                        && !in_array($blockname, $standardblocks, true)
                ) {
                    $found[] = ['type' => 'block', 'name' => $blockname];
                }
            }

            return $found;
        } finally {
            remove_dir($scandir);
        }
    }

    /**
     * Text content of every element with one of the given names, streamed
     * with XMLReader so a large member (questions.xml of a big course) is
     * never held in memory whole. Returns [] for a missing or unparseable
     * file; entity/network resolution stays off (attacker-supplied XML).
     *
     * Collection is DISTINCT and capped at MAX_REQUIRED_PLUGINS values: the
     * caller only ever compares the set against a plugins list, anything
     * past the cap could never be stored anyway, and a crafted member with
     * millions of matching elements must not grow an unbounded array while
     * "streaming".
     *
     * @param string $path absolute path to an extracted XML member
     * @param array $names element local names to collect
     * @return array distinct trimmed text contents, in first-seen order
     */
    protected static function collect_element_texts(string $path, array $names): array {
        if (!is_readable($path)) {
            return [];
        }
        $found = [];
        $previous = libxml_use_internal_errors(true);
        $reader = new \XMLReader();
        if ($reader->open($path, null, LIBXML_NONET)) {
            while (count($found) < self::MAX_REQUIRED_PLUGINS && @$reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->localName, $names, true)) {
                    $found[trim($reader->readString())] = true;
                }
            }
            $reader->close();
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return array_keys($found);
    }

    /**
     * Names of every subplugin_* element in an activity XML member. Only the
     * element NAMES are read — a subplugin's data block is wrapped in an
     * element named subplugin_<type>_<name>_<connectionpoint>, emitted (even
     * empty) whenever the source site had the subplugin installed with
     * backup support, so the name alone proves the dependency.
     *
     * @param string $path absolute path to an extracted activity XML member
     * @return array distinct element names beginning with "subplugin_"
     */
    protected static function collect_subplugin_element_names(string $path): array {
        if (!is_readable($path)) {
            return [];
        }
        $found = [];
        $previous = libxml_use_internal_errors(true);
        $reader = new \XMLReader();
        if ($reader->open($path, null, LIBXML_NONET)) {
            // Same distinct-and-capped rule as collect_element_texts().
            while (count($found) < self::MAX_REQUIRED_PLUGINS && @$reader->read()) {
                if (
                    $reader->nodeType === \XMLReader::ELEMENT
                        && str_starts_with($reader->localName, 'subplugin_')
                ) {
                    $found[$reader->localName] = true;
                }
            }
            $reader->close();
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return array_keys($found);
    }

    /**
     * Split a subplugin_<type>_<name>_<connectionpoint> element name into
     * [type, name].
     *
     * The type is always exactly the first underscore-separated token (core
     * rejects subplugin types containing underscores), and it must be a
     * subplugin type this site's copy of the mod declares. The connection
     * point is the last token when it matches the mod's known set
     * (SUBPLUGIN_CONNECTION_POINTS); for a connection point this parser
     * does not know yet, the last token is stripped as a fallback — safe
     * because every connection point core has ever used is a single
     * underscore-free token, while the NAME in between may itself contain
     * underscores and is therefore taken greedily as "the middle".
     *
     * @param string $element the XML element name
     * @param string $modname the standard mod whose XML contained it
     * @return array|null [subplugintype, subpluginname], or null if the
     *                    element does not decompose into a subplugin of a
     *                    type this mod declares
     */
    protected static function split_subplugin_element(string $element, string $modname): ?array {
        $rest = substr($element, strlen('subplugin_'));
        $firstsep = strpos($rest, '_');
        if ($firstsep === false) {
            return null;
        }
        $subtype = substr($rest, 0, $firstsep);
        $declaredtypes = array_keys(\core_component::get_subplugins('mod_' . $modname) ?? []);
        if (!in_array($subtype, $declaredtypes, true)) {
            return null;
        }
        $middle = substr($rest, $firstsep + 1);
        $lastsep = strrpos($middle, '_');
        if ($lastsep === false || $lastsep === 0) {
            return null;
        }
        $connectionpoint = substr($middle, $lastsep + 1);
        $knownpoints = self::SUBPLUGIN_CONNECTION_POINTS[$modname] ?? [];
        if ($knownpoints && !in_array($connectionpoint, $knownpoints, true)) {
            // Unknown connection point for a mod with a known set: still
            // strip the last token (core connection points are single
            // tokens), the fallback documented above.
            debugging(
                "local_oerexchange: unknown subplugin connection point '{$connectionpoint}' in {$element}",
                DEBUG_DEVELOPER
            );
        }
        $subname = substr($middle, 0, $lastsep);
        if (!self::is_valid_detected_name($subname)) {
            return null;
        }
        return [$subtype, $subname];
    }

    /**
     * Whether a plugin name pulled out of attacker-supplied archive content
     * is shaped like a real plugin name — core's own is_valid_plugin_name()
     * rule for non-mod plugins (lowercase alphanumeric + single underscores,
     * starts with a letter, no trailing underscore). Anything else is
     * dropped rather than stored/displayed.
     *
     * @param string $name candidate plugin name
     * @return bool
     */
    protected static function is_valid_detected_name(string $name): bool {
        return $name !== '' && strlen($name) <= 100
            && (bool) preg_match('/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/', $name);
    }
}
