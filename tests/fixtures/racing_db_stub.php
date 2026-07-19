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
 * Test fixture: minimal $DB stand-in for simulating a lost TOCTOU race.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange;

/**
 * Minimal $DB stand-in for simulating a lost TOCTOU race inside a single
 * PHP process. profile_manager's guard checks (an existence check by userid,
 * or the slug_available() check) and the write that follows them cannot
 * actually be interleaved by a second concurrent request in a synchronous
 * test, so this proxy fakes that interleaving instead: the *first* call to
 * get_record() whose table and conditions match the ones given to the
 * constructor lies by returning false, standing in for a guard check that
 * ran before a concurrent caller's conflicting row existed. Every other
 * call — crucially, the write that follows — is forwarded untouched to the
 * real $DB, so it hits a genuine unique-index violation (because the test
 * has already inserted the conflicting row directly via the real $DB
 * beforehand), producing a real \dml_write_exception rather than a
 * fabricated one.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class racing_db_stub {
    /** @var \moodle_database the real $DB, forwarded to for everything except the one lied-about call */
    private $real;
    /** @var string table name to lie about, once */
    private $table;
    /** @var array conditions to lie about, once */
    private $conditions;
    /** @var bool whether the one-time lie has already been told */
    private $lied = false;

    /**
     * Constructor.
     *
     * @param \moodle_database $real the real $DB
     * @param string $table table name of the get_record() call to lie about once
     * @param array $conditions conditions of the get_record() call to lie about once
     */
    public function __construct(\moodle_database $real, string $table, array $conditions) {
        $this->real = $real;
        $this->table = $table;
        $this->conditions = $conditions;
    }

    /**
     * Lies (returns false) exactly once, for the matching table/conditions;
     * every other call is forwarded to the real $DB.
     *
     * @param string $table
     * @param array $conditions
     * @param string $fields
     * @param int $strictness
     * @return \stdClass|false
     */
    public function get_record($table, array $conditions, $fields = '*', $strictness = \IGNORE_MISSING) {
        if (!$this->lied && $table === $this->table && $conditions == $this->conditions) {
            $this->lied = true;
            return false;
        }
        return $this->real->get_record($table, $conditions, $fields, $strictness);
    }

    /**
     * Forwards every other call untouched to the real $DB.
     *
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public function __call($name, $args) {
        return $this->real->$name(...$args);
    }
}
