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

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\sandbox\bundle_stamp;

/**
 * Tests for comparing the saved sandbox configuration's stamp against the
 * deployed bundle's own stamp file. interpret() is the pure half and is
 * tested thoroughly; deployed() wraps it around an HTTP fetch and is tested
 * only for the no-URL-configured case, per SANDBOX-CONFIG-PLAN.md Task 7 —
 * exercising a real fetch belongs to live verification (Task 12), not a unit
 * test that must never touch the network.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(bundle_stamp::class)]
final class sandbox_bundle_stamp_test extends \advanced_testcase {
    public function test_no_sandbox_url_is_reported_as_unverifiable_not_mismatched(): void {
        $this->resetAfterTest();
        set_config('sandboxbaseurl', '', 'local_oerexchange');

        $result = bundle_stamp::deployed();

        $this->assertNull($result['stamp']);
        $this->assertNotNull($result['error']);
    }

    public function test_a_malformed_stamp_document_is_an_error_not_a_stamp(): void {
        $this->resetAfterTest();

        $this->assertNull(bundle_stamp::interpret('not json')['stamp']);
        $this->assertNotNull(bundle_stamp::interpret('not json')['error']);
        $this->assertNull(bundle_stamp::interpret('{"built":123}')['stamp']);
    }

    public function test_a_well_formed_stamp_document_is_read(): void {
        $this->resetAfterTest();

        $result = bundle_stamp::interpret('{"stamp":"cfg-7f3a91b0c2d4","built":1785000000}');

        $this->assertSame('cfg-7f3a91b0c2d4', $result['stamp']);
        $this->assertSame(1785000000, $result['built']);
        $this->assertNull($result['error']);
    }

    /**
     * A "could not verify" result must be distinguishable from a mismatch —
     * a fetch failure is not evidence of disagreement, and rendering it as a
     * mismatch would train an admin to ignore the real warning
     * (SANDBOX-CONFIG-DESIGN.md "Error handling").
     */
    public function test_an_unverifiable_result_never_carries_a_stamp_to_compare(): void {
        $this->resetAfterTest();
        set_config('sandboxbaseurl', '', 'local_oerexchange');

        $result = bundle_stamp::deployed();

        $this->assertNull($result['stamp']);
        $this->assertNull($result['built']);
    }

    public function test_a_document_missing_the_stamp_key_entirely_is_malformed(): void {
        $this->assertNull(bundle_stamp::interpret('{}')['stamp']);
        $this->assertNotNull(bundle_stamp::interpret('{}')['error']);
    }

    public function test_an_empty_stamp_value_is_malformed(): void {
        $this->assertNull(bundle_stamp::interpret('{"stamp":""}')['stamp']);
        $this->assertNotNull(bundle_stamp::interpret('{"stamp":""}')['error']);
    }

    public function test_a_missing_built_value_is_not_an_error(): void {
        $result = bundle_stamp::interpret('{"stamp":"cfg-000000000000"}');

        $this->assertSame('cfg-000000000000', $result['stamp']);
        $this->assertNull($result['built']);
        $this->assertNull($result['error']);
    }
}
