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
 * URL slugs: the one copy of "is this slug usable, is it free, and what do we
 * call this thing when the name it was seeded from is unusable".
 *
 * Two things in this plugin are addressed by a human-readable slug in a URL
 * path segment — an educator profile (/u/{slug}) and a collection
 * (/c/{slug}) — and both need the same four behaviours: a charset the router
 * will accept, an availability test that treats "already mine" as free, a
 * collision-suffixing generator, and one agreed answer to the race the first
 * two cannot win (the unique index decides, and the loser must see the
 * documented error_slugtaken rather than a raw dml_write_exception).
 *
 * This class exists because profile_manager had all four inline. A second
 * inline copy for collections is the shape of the bug this plugin has already
 * been bitten by — see resource_manager::user_can_edit_resource()'s docblock
 * on two differently-guarded copies of one rule. Everything here is
 * table-agnostic: the caller names its table and its owner column, and the
 * rules themselves live in exactly one place.
 *
 * The charset is deliberately the one profile slugs have always allowed
 * ([A-Za-z0-9_-]), NOT a stricter lowercase-only rule. Existing profile slugs
 * are seeded from usernames and may contain uppercase; tightening the
 * validator would retrospectively invalidate slugs already in the database
 * and in other people's bookmarks. slugify() lowercases what it generates, so
 * new slugs are still lowercase by convention without the validator having to
 * forbid anything.
 *
 * Seed PREPARATION is the one thing deliberately NOT unified, because the two
 * callers genuinely differ and unifying them would have silently changed
 * existing behaviour. A username is one token and has always been compacted
 * by deleting what is unusable (compact_seed(): 'Adam.Jenkins' ->
 * 'AdamJenkins'); a collection title is a sentence and has to keep its word
 * boundaries (slugify(): 'Open Science: week 1' -> 'open-science-week-1').
 * Running usernames through slugify() would start minting profile slugs in a
 * different spelling from every slug already issued, for no benefit.
 * Validation, availability, collision suffixing and the race contract — the
 * parts that must never disagree between the two — are shared.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slug_helper {
    /** @var int Longest slug the database columns hold (profiles.slug, collections.slug). */
    const MAX_LENGTH = 100;

    /**
     * Headroom left when seeding a slug, so the collision suffix ('-2', and
     * on a pathological site '-1234') still fits inside MAX_LENGTH.
     *
     * @var int
     */
    const SEED_MAX_LENGTH = 90;

    /**
     * Whether a slug is usable in a URL path segment.
     *
     * @param string $slug
     * @return bool
     */
    public static function is_valid(string $slug): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_LENGTH . '}$/', $slug);
    }

    /**
     * Turn free text — a collection title, a username — into a slug seed.
     *
     * Returns '' when nothing usable survives (a title written entirely in a
     * non-Latin script, which is a perfectly reasonable title). Callers must
     * handle that by supplying their own fallback; generate_unique() takes one
     * for exactly this reason.
     *
     * Transliteration is deliberately not attempted. Core has no
     * general-purpose transliterator, iconv()'s //TRANSLIT output is
     * locale-dependent and differs between servers, and a slug whose spelling
     * depends on which machine generated it is worse than a numbered
     * fallback.
     *
     * @param string $text
     * @return string a valid slug seed, or '' if nothing usable remains
     */
    public static function slugify(string $text): string {
        $slug = \core_text::strtolower(trim($text));
        // Anything that is not already slug-safe becomes a separator, so
        // "Open Science: week 1" reads as "open-science-week-1" rather than
        // "opensciencweek1".
        $slug = preg_replace('/[^A-Za-z0-9_-]+/u', '-', $slug);
        $slug = preg_replace('/-{2,}/', '-', (string) $slug);
        $slug = trim((string) $slug, '-_');
        $slug = substr($slug, 0, self::SEED_MAX_LENGTH);
        // A substr() can leave a trailing separator behind after the cut.
        $slug = rtrim($slug, '-_');

        return self::is_valid($slug) ? $slug : '';
    }

    /**
     * Turn a single token — a username — into a slug seed by deleting
     * everything unusable rather than replacing it with separators.
     *
     * This is profile_manager's original rule, preserved exactly (case
     * included) so that delegating to this class does not change the spelling
     * of the slugs new profiles are issued: 'Adam.Jenkins' stays
     * 'AdamJenkins', not 'adam-jenkins'. Do not "fix" this to call slugify();
     * see the class docblock.
     *
     * @param string $text
     * @return string a valid slug seed, or '' if nothing usable remains
     */
    public static function compact_seed(string $text): string {
        $seed = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $text);
        $seed = substr($seed, 0, self::SEED_MAX_LENGTH);

        return self::is_valid($seed) ? $seed : '';
    }

    /**
     * Whether a slug is free to take in a table (or already belongs to the
     * given owner, who is allowed to keep it).
     *
     * $excludeownerid 0 means "this row does not exist yet", so it never
     * matches an existing holder: the columns this is used with are NOT NULL
     * ids, and a stray 0 in one of them must not read as "that slug is mine"
     * for every brand-new row. Same guard, same reason, as
     * coauthor_manager::is_coauthor().
     *
     * @param string $table the table holding the slug column, without braces
     * @param string $slug
     * @param string $ownercolumn the column identifying the slug's owner, e.g. 'userid' or 'id'
     * @param int $excludeownerid the slug is allowed if it already belongs to this owner; 0 for
     *                            a row that does not exist yet
     * @return bool
     */
    public static function available(string $table, string $slug, string $ownercolumn, int $excludeownerid): bool {
        global $DB;

        $holder = $DB->get_record($table, ['slug' => $slug], $ownercolumn);

        return !$holder || ($excludeownerid !== 0 && (int) $holder->{$ownercolumn} === $excludeownerid);
    }

    /**
     * Validate a slug a human typed, and refuse it if it is unusable or taken.
     *
     * The two failures are deliberately different exceptions: "those
     * characters are not allowed" and "somebody already has that" need
     * different corrections from the person who typed it.
     *
     * This check is inherently TOCTOU-prone — a concurrent writer can take
     * the slug between here and the caller's INSERT/UPDATE. That is what the
     * unique index is for, and what taken_exception() is for; this method is
     * the courtesy that lets the overwhelmingly common case fail with a clear
     * message before any write is attempted.
     *
     * @param string $table
     * @param string $slug
     * @param string $ownercolumn
     * @param int $excludeownerid
     * @throws \moodle_exception error_invalidslug, error_slugtaken
     */
    public static function validate(string $table, string $slug, string $ownercolumn, int $excludeownerid): void {
        if (!self::is_valid($slug)) {
            throw new \moodle_exception('error_invalidslug', 'local_oerexchange');
        }
        if (!self::available($table, $slug, $ownercolumn, $excludeownerid)) {
            throw self::taken_exception();
        }
    }

    /**
     * The one exception a caller raises when the unique index — not the
     * pre-check — is what caught a slug collision.
     *
     * Every slug writer wraps its own INSERT/UPDATE in a try/catch and
     * converts \dml_write_exception into this, so the loser of a race sees the
     * same documented error_slugtaken contract as somebody who simply typed a
     * slug that was already taken. Returned rather than thrown so the catch
     * block reads as `throw slug_helper::taken_exception();` at the point it
     * actually happens.
     *
     * @return \moodle_exception
     */
    public static function taken_exception(): \moodle_exception {
        return new \moodle_exception('error_slugtaken', 'local_oerexchange');
    }

    /**
     * Generate a slug nobody holds yet from a prepared seed, falling back to
     * $fallback when the seed is empty or unusable.
     *
     * $base is already-prepared: the caller ran it through slugify() or
     * compact_seed(), whichever matches what it is naming. Collisions are
     * resolved by suffixing -2, -3, ... which is what profile slugs have
     * always done. The suffix is appended to the (already length-capped)
     * seed, so the result always fits MAX_LENGTH.
     *
     * Still TOCTOU-prone, by design: the caller inserts under a unique index
     * and converts the loss into taken_exception(). Looping here until an
     * insert succeeded would mean this method doing the caller's write.
     *
     * @param string $table
     * @param string $base a prepared seed from slugify()/compact_seed(), or '' if none survived
     * @param string $ownercolumn
     * @param int $excludeownerid 0 for a row that does not exist yet
     * @param string $fallback used when $base is empty; e.g. 'user42', 'collection42'
     * @return string
     * @throws \coding_exception if $fallback is not a usable slug either
     */
    public static function generate_unique(
        string $table,
        string $base,
        string $ownercolumn,
        int $excludeownerid,
        string $fallback
    ): string {
        if (!self::is_valid($base)) {
            $base = self::compact_seed($fallback);
        }
        if ($base === '') {
            // A caller that cannot name a usable fallback has a bug: every
            // caller has an id to fall back on ('user42', 'collection42').
            throw new \coding_exception('slug_helper::generate_unique() needs a slugifiable fallback');
        }

        $slug = $base;
        $suffix = 2;
        while (!self::available($table, $slug, $ownercolumn, $excludeownerid)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
