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

namespace local_oerexchange\local\allowlist;

/**
 * Reads $plugin->supported and $plugin->dependencies out of a version.php.
 *
 * These are the two declarations core's own plugin-zip parser does not read:
 * \core\update\validator::parse_version_php() matches only
 * version|maturity|release|requires|component
 * (lib/classes/update/validator.php:537-540), and core_plugin_manager still
 * carries "TODO Check for missing dependencies during validation"
 * (lib/classes/plugin_manager.php:1382). Everything else this plugin needs
 * about a candidate zip comes from core's validator; only these two are ours.
 *
 * The file is TOKENISED, never included and never evaluated. A version.php
 * reaching this class came from an admin-supplied URL or upload, and running
 * it would make the Exchange the execution host for arbitrary third-party
 * code — the one thing this whole ingest path must never do. Tokenising also
 * happens to be more correct than the regex alternative: a mention of
 * "$plugin->supported = [...]" inside a comment or a string literal is a
 * distinct token type and is ignored for free.
 *
 * Anything not written as a plain literal is reported as absent rather than
 * guessed at — see the return contracts below. Callers treat "absent"
 * conservatively (branch_mapper falls back to $plugin->requires and flags the
 * result as inferred); a guess would silently produce allowlist rows for
 * branches nobody verified.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class version_php_scanner {
    /**
     * The value ANY_VERSION carries (lib/classes/component.php:51). Spelled
     * out rather than referenced so this class stays a pure string-in,
     * data-out unit that a test can exercise without Moodle's constants.
     */
    public const ANY = 'any';

    /**
     * Branches the plugin declares support for, e.g. [500, 502].
     *
     * @param string $source raw contents of a version.php
     * @return int[]|null the declared branches; [] when declared empty; null
     *                    when not declared, or declared as anything other
     *                    than a literal array of integers
     */
    public static function supported(string $source): ?array {
        $elements = self::array_literal_elements($source, 'supported');
        if ($elements === null) {
            return null;
        }

        $branches = [];
        foreach ($elements as $element) {
            // One integer literal and nothing else. A computed element makes
            // the whole declaration unreadable rather than partly readable:
            // a partial branch list would look authoritative while silently
            // omitting a branch the plugin does support.
            if (count($element) !== 1 || $element[0][0] !== T_LNUMBER) {
                return null;
            }
            $branches[] = (int) $element[0][1];
        }

        return $branches;
    }

    /**
     * The plugin's frankenstyle component name.
     *
     * Core's validator reads this too, but it is needed *before* the
     * validator can run: unzipping a package into a correctly named plugin
     * directory (which is what the validator then checks) requires already
     * knowing the name.
     *
     * @param string $source raw contents of a version.php
     * @return string|null null when not declared as a plain string literal
     */
    public static function component(string $source): ?string {
        $token = self::scalar_at($source, 'component');

        if ($token === null || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $component = self::literal_string($token[1]);

        // A component is always type_name; a bare word is a malformed
        // declaration, not a plugin this ingest can place.
        if ($component === null || !str_contains($component, '_')) {
            return null;
        }

        return $component;
    }

    /**
     * $plugin->version — the plugin's own build number.
     *
     * @param string $source raw contents of a version.php
     * @return int|null
     */
    public static function plugin_version(string $source): ?int {
        return self::integer_field($source, 'version');
    }

    /**
     * $plugin->requires — the minimum core version.
     *
     * @param string $source raw contents of a version.php
     * @return int|null
     */
    public static function requires(string $source): ?int {
        return self::integer_field($source, 'requires');
    }

    /**
     * $plugin->release — the human-facing version string, for display only.
     *
     * @param string $source raw contents of a version.php
     * @return string|null
     */
    public static function release(string $source): ?string {
        $token = self::scalar_at($source, 'release');

        if ($token === null || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $value = substr($token[1], 1, -1);

        // Release strings are free-form ("1.0.4", "2.1 (Build: 20260101)"),
        // so unlike a component name there is no tight shape to check
        // against. Accept the printable subset real ones use and reject
        // anything else rather than implementing PHP's escape rules.
        if (!preg_match('/^[A-Za-z0-9 ._+():,-]{1,30}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * A plain integer field on $plugin.
     *
     * Version numbers are occasionally written as floats (core's own
     * public/version.php uses 2026042001.06), so the fractional part is
     * accepted and truncated rather than making the field unreadable.
     *
     * @param string $source
     * @param string $property
     * @return int|null
     */
    private static function integer_field(string $source, string $property): ?int {
        $token = self::scalar_at($source, $property);

        if ($token === null || ($token[0] !== T_LNUMBER && $token[0] !== T_DNUMBER)) {
            return null;
        }

        return (int) $token[1];
    }

    /**
     * The first branch the plugin declares itself incompatible with.
     *
     * Core treats this as an open-ended upper bound: a branch >= this value
     * is not compatible (\core\plugininfo\base::is_core_compatible_satisfied(),
     * lib/classes/plugininfo/base.php:456-462). It is declared independently
     * of supported() and rules branches out on its own, so branch_mapper has
     * to read it even when a supported range is present.
     *
     * @param string $source raw contents of a version.php
     * @return int|null null when not declared or not a plain integer literal
     */
    public static function incompatible(string $source): ?int {
        $token = self::scalar_at($source, 'incompatible');

        if ($token === null || $token[0] !== T_LNUMBER) {
            return null;
        }

        return (int) $token[1];
    }

    /**
     * Other plugins this one declares it needs.
     *
     * Unlike supported(), an unreadable single entry drops just that entry:
     * the readable dependencies are still worth resolving, and a dropped one
     * surfaces to the admin as a plugin that simply was not found rather than
     * as a wrong answer.
     *
     * @param string $source raw contents of a version.php
     * @return array<string, int|string> frankenstyle component => required
     *                                   version, or self::ANY
     */
    public static function dependencies(string $source): array {
        $elements = self::array_literal_elements($source, 'dependencies');
        if ($elements === null) {
            return [];
        }

        $dependencies = [];
        foreach ($elements as $element) {
            // Expect exactly: '<component>' => <version>.
            if (count($element) !== 3 || $element[1][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            if ($element[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $component = self::literal_string($element[0][1]);
            if ($component === null) {
                continue;
            }

            $version = self::dependency_version($element[2]);
            if ($version === null) {
                continue;
            }

            $dependencies[$component] = $version;
        }

        return $dependencies;
    }

    /**
     * The required-version half of a dependency entry.
     *
     * @param array $token [id, text] as produced by significant_tokens()
     * @return int|string|null null when the value is not a readable literal
     */
    private static function dependency_version(array $token): int|string|null {
        if ($token[0] === T_LNUMBER) {
            return (int) $token[1];
        }
        // The ANY_VERSION constant, which report/competency/version.php and
        // others use in place of a number.
        if ($token[0] === T_STRING && $token[1] === 'ANY_VERSION') {
            return self::ANY;
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return self::literal_string($token[1]);
        }

        return null;
    }

    /**
     * Find `$plugin-><property> = [ ... ];` and return its elements.
     *
     * @param string $source raw contents of a version.php
     * @param string $property 'supported' or 'dependencies'
     * @return array[]|null one entry per element, each a list of [id, text]
     *                      tokens; null when there is no such assignment or
     *                      its right-hand side is not an array literal
     */
    private static function array_literal_elements(string $source, string $property): ?array {
        $tokens = self::significant_tokens($source);
        $rhs = self::find_assignment($tokens, $property);

        if ($rhs === null) {
            return null;
        }

        return self::elements_at($tokens, $rhs);
    }

    /**
     * The single token assigned to `$plugin-><property>`, for scalar fields.
     *
     * @param string $source raw contents of a version.php
     * @param string $property e.g. 'incompatible'
     * @return array|null [id, text], or null when there is no such assignment
     */
    private static function scalar_at(string $source, string $property): ?array {
        $tokens = self::significant_tokens($source);
        $rhs = self::find_assignment($tokens, $property);

        if ($rhs === null) {
            return null;
        }

        // Only a bare literal counts: `= 500;`. Anything longer is an
        // expression this class deliberately does not evaluate.
        if (!isset($tokens[$rhs + 1]) || $tokens[$rhs + 1][1] !== ';') {
            return null;
        }

        return $tokens[$rhs];
    }

    /**
     * Index of the first token after `$plugin-><property> =`.
     *
     * @param array $tokens significant tokens
     * @param string $property
     * @return int|null null when the file makes no such assignment
     */
    private static function find_assignment(array $tokens, string $property): ?int {
        $count = count($tokens);

        for ($i = 0; $i + 4 < $count; $i++) {
            // Both spellings occur in the wild; core's own parser accepts
            // $module-> alongside $plugin-> for the fields it reads.
            if ($tokens[$i][0] !== T_VARIABLE || !in_array($tokens[$i][1], ['$plugin', '$module'], true)) {
                continue;
            }
            if ($tokens[$i + 1][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            if ($tokens[$i + 2][0] !== T_STRING || $tokens[$i + 2][1] !== $property) {
                continue;
            }
            if ($tokens[$i + 3][1] !== '=') {
                continue;
            }

            return $i + 4;
        }

        return null;
    }

    /**
     * Split the array literal starting at $start into its elements.
     *
     * @param array $tokens significant tokens
     * @param int $start index of the literal's first token
     * @return array[]|null null when $start does not begin an array literal
     */
    private static function elements_at(array $tokens, int $start): ?array {
        $count = count($tokens);
        $open = $start;

        // Both the long array syntax and the short one.
        if ($tokens[$open][0] === T_ARRAY) {
            $open++;
            if ($open >= $count || $tokens[$open][1] !== '(') {
                return null;
            }
        } else if ($tokens[$open][1] !== '[') {
            // A variable, a function call, a concatenation — not a literal.
            return null;
        }

        $elements = [];
        $current = [];
        $depth = 0;

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($text === '[' || $text === '(') {
                $depth++;
                if ($depth === 1) {
                    // The literal's own opening bracket.
                    continue;
                }
            } else if ($text === ']' || $text === ')') {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $elements[] = $current;
                    }
                    return $elements;
                }
            } else if ($text === ',' && $depth === 1) {
                // Element boundary. A trailing comma leaves $current empty,
                // which is why the append is guarded.
                if ($current !== []) {
                    $elements[] = $current;
                }
                $current = [];
                continue;
            }

            $current[] = $tokens[$i];
        }

        // Unterminated literal — a truncated or malformed file.
        return null;
    }

    /**
     * Tokenise, dropping whitespace and comments.
     *
     * Single-character tokens are normalised to [null, '<char>'] so callers
     * can read [0] and [1] on every entry without an is_array() dance.
     *
     * @param string $source
     * @return array[] list of [int|null $id, string $text]
     */
    private static function significant_tokens(string $source): array {
        // A file that is not PHP at all tokenises to a single T_INLINE_HTML
        // token rather than throwing, so no error suppression is needed for
        // the normal "this zip's version.php is junk" case; the @ guards
        // against a ParseError-adjacent warning on badly truncated input.
        $raw = @token_get_all($source);

        $tokens = [];
        foreach ($raw as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $tokens[] = [$token[0], $token[1]];
            } else {
                $tokens[] = [null, $token];
            }
        }

        return $tokens;
    }

    /**
     * The content of a single- or double-quoted literal, if it is a
     * frankenstyle-shaped name.
     *
     * Rather than implement PHP's escape rules, this accepts only the
     * character set a component name (or the ANY marker) can legally use and
     * rejects everything else — an escape sequence, an interpolated variable
     * or a stray quote therefore reads as "not a component" and is dropped.
     *
     * @param string $text the token text, quotes included
     * @return string|null
     */
    private static function literal_string(string $text): ?string {
        $value = substr($text, 1, -1);

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            return null;
        }

        return $value;
    }
}
