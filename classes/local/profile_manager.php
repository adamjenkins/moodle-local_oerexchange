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
 * Educator author profiles: get-or-create, slug management, metrics.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_manager {
    /**
     * Fetch a user's profile, creating one (auto-slug, visible) if none exists.
     * Called lazily on first publish, per the design's "auto-created once they
     * publish" decision.
     *
     * @param int $userid
     * @return \stdClass
     */
    public static function get_or_create_for_user(int $userid): \stdClass {
        global $DB;

        $existing = $DB->get_record('local_oerexchange_profiles', ['userid' => $userid]);
        if ($existing) {
            return $existing;
        }

        $now = time();
        $slug = self::generate_unique_slug($userid);
        try {
            $id = $DB->insert_record('local_oerexchange_profiles', (object) [
                'userid' => $userid,
                'slug' => $slug,
                'bio' => '',
                'expertise' => json_encode([]),
                'orcidurl' => '',
                'linkedinurl' => '',
                'researchmapurl' => '',
                'visible' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            // The existence check above is TOCTOU-prone: a concurrent
            // get_or_create_for_user() call for the same userid can insert
            // its row between our check and this insert, tripping the
            // unique index on userid (db/install.xml). This is a
            // get-OR-create, so losing that race should return the row the
            // other call just created, not raise an error — error_slugtaken
            // belongs to save()'s user-chosen-slug conflict, a different
            // failure with a different correct resolution.
            return $DB->get_record('local_oerexchange_profiles', ['userid' => $userid], '*', MUST_EXIST);
        }

        return $DB->get_record('local_oerexchange_profiles', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Fetch a profile by its slug.
     *
     * @param string $slug
     * @return \stdClass|null
     */
    public static function get_by_slug(string $slug): ?\stdClass {
        global $DB;
        return $DB->get_record('local_oerexchange_profiles', ['slug' => $slug]) ?: null;
    }

    /**
     * Fetch a profile by its owning user id.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_by_userid(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_oerexchange_profiles', ['userid' => $userid]) ?: null;
    }

    /**
     * Slugs are used in a URL path segment — restrict to a safe, readable charset.
     *
     * @param string $slug
     * @return bool
     */
    public static function is_valid_slug(string $slug): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,100}$/', $slug);
    }

    /**
     * Check whether a slug is free to take (or already belongs to the given user).
     *
     * @param string $slug
     * @param int $excludeuserid the slug is allowed if it already belongs to this user
     * @return bool
     */
    public static function slug_available(string $slug, int $excludeuserid): bool {
        global $DB;
        $holder = $DB->get_record('local_oerexchange_profiles', ['slug' => $slug], 'userid');
        return !$holder || (int) $holder->userid === $excludeuserid;
    }

    /**
     * Save a profile's editable fields. Metrics/badges/course list are never
     * part of $data — they're always computed, never hand-entered.
     *
     * @param int $userid
     * @param array $data slug, bio, expertise (array), orcidurl, linkedinurl, researchmapurl, visible (bool)
     */
    public static function save(int $userid, array $data): void {
        global $DB;

        if (!self::is_valid_slug($data['slug'])) {
            throw new \moodle_exception('error_invalidslug', 'local_oerexchange');
        }
        if (!self::slug_available($data['slug'], $userid)) {
            throw new \moodle_exception('error_slugtaken', 'local_oerexchange');
        }

        $profile = self::get_or_create_for_user($userid);
        try {
            $DB->update_record('local_oerexchange_profiles', (object) [
                'id' => $profile->id,
                'slug' => $data['slug'],
                'bio' => $data['bio'],
                'expertise' => json_encode(array_values($data['expertise'])),
                'orcidurl' => $data['orcidurl'],
                'linkedinurl' => $data['linkedinurl'],
                'researchmapurl' => $data['researchmapurl'],
                'visible' => $data['visible'] ? 1 : 0,
                'timemodified' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // The is_valid_slug()/slug_available() checks above are
            // TOCTOU-prone: a concurrent save() can take $data['slug']
            // between our check and this write. The unique index on slug
            // (db/install.xml) prevents any data corruption, but without
            // this catch the loser of that race would see a raw
            // dml_write_exception instead of the documented error_slugtaken
            // contract every other slug-taken path already gives.
            throw new \moodle_exception('error_slugtaken', 'local_oerexchange');
        }
    }

    /**
     * Profile-page totals, computed on read from existing catalogue data —
     * no new counter infrastructure. No time-series data exists, so this
     * returns current totals only, not a trend (design doc, "Data model").
     *
     * @param int $userid
     * @return array{resourcecount:int,downloadtotal:int,avgrating:?float,membersince:?int}
     */
    public static function get_metrics(int $userid): array {
        global $DB;

        $resources = $DB->get_records('local_oerexchange_resources', [
            'creatorid' => $userid, 'status' => 'published',
        ], '', 'id, downloadcount, timeshared');

        $resourcecount = count($resources);
        $downloadtotal = array_sum(array_map(fn($r) => (int) $r->downloadcount, $resources));
        $membersince = $resourcecount
            ? min(array_map(fn($r) => (int) $r->timeshared, $resources))
            : null;

        $avgrating = null;
        if ($resourcecount) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($resources));
            $avgrating = $DB->get_field_sql(
                "SELECT AVG(rating) FROM {local_oerexchange_reviews}
                  WHERE resourceid {$insql} AND status = 'visible' AND rating IS NOT NULL",
                $inparams
            );
            $avgrating = $avgrating !== false && $avgrating !== null ? (float) $avgrating : null;
        }

        return [
            'resourcecount' => $resourcecount,
            'downloadtotal' => $downloadtotal,
            'avgrating' => $avgrating,
            'membersince' => $membersince,
        ];
    }

    /**
     * Tombstone a single resource as part of a GDPR full-deletion request:
     * delete its files/versions/reviews/reports, scrub descriptive metadata,
     * keep the row (stable id, status = 'deleted', creatorid = 0) so existing
     * links to it (e.g. a client site's "View on Exchange" button) resolve to
     * a graceful message instead of breaking. See
     * dev-docs/oer-platform/EDUCATOR-PROFILES-DESIGN.md "Privacy / GDPR".
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     */
    public static function delete_creator_resource(\stdClass $resource): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $fs = get_file_storage();
            $context = \context_system::instance();

            $versionids = $DB->get_fieldset_select(
                'local_oerexchange_versions',
                'id',
                'resourceid = ?',
                [$resource->id]
            );
            foreach ($versionids as $versionid) {
                $fs->delete_area_files($context->id, 'local_oerexchange', 'resource', $versionid);
            }
            $fs->delete_area_files($context->id, 'local_oerexchange', 'coverimage', $resource->id);

            $DB->delete_records('local_oerexchange_versions', ['resourceid' => $resource->id]);
            $DB->delete_records('local_oerexchange_reviews', ['resourceid' => $resource->id]);
            $DB->delete_records('local_oerexchange_reports', ['resourceid' => $resource->id]);

            $DB->update_record('local_oerexchange_resources', (object) [
                'id' => $resource->id,
                'title' => '',
                'summary' => '',
                'tags' => '',
                'status' => 'deleted',
                'creatorid' => 0,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Generate a unique slug seeded from the user's username, falling back to
     * userN / userN-2 / userN-3 ... on collision.
     *
     * @param int $userid
     * @return string
     */
    protected static function generate_unique_slug(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'username', MUST_EXIST);
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', $user->username);
        if ($base === '') {
            $base = 'user' . $userid;
        }
        $base = substr($base, 0, 90);

        $slug = $base;
        $suffix = 2;
        while (!self::slug_available($slug, $userid)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
