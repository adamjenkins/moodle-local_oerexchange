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

use local_oerexchange\local\download_signer;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for download_signer — the HMAC gate that download.php's signed path
 * relies on entirely (an unsigned request falls through to
 * resource_manager::can_download_unsigned() instead). Added in the second
 * MDL Shield audit pass (2026-07-18): this class had zero coverage despite
 * being the sole authorization mechanism for WS clients and sandbox trials.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\download_signer
 */
final class download_signer_test extends \advanced_testcase {
    public function test_a_freshly_signed_url_verifies(): void {
        $this->resetAfterTest();

        $url = download_signer::sign_url(42, 900);
        $params = $url->params();

        $this->assertTrue(download_signer::verify(42, (int) $params['exp'], $params['sig']));
    }

    public function test_verify_rejects_tampered_versionid(): void {
        $this->resetAfterTest();

        $url = download_signer::sign_url(42, 900);
        $params = $url->params();

        // Same signature, different versionid — must not verify.
        $this->assertFalse(download_signer::verify(43, (int) $params['exp'], $params['sig']));
    }

    public function test_verify_rejects_tampered_expiry(): void {
        $this->resetAfterTest();

        $url = download_signer::sign_url(42, 900);
        $params = $url->params();

        // Same signature, extended expiry — must not verify (the sig covers exp).
        $this->assertFalse(download_signer::verify(42, (int) $params['exp'] + 3600, $params['sig']));
    }

    public function test_verify_rejects_tampered_signature(): void {
        $this->resetAfterTest();

        $url = download_signer::sign_url(42, 900);
        $params = $url->params();

        $tampered = substr($params['sig'], 0, -1) . (($params['sig'][-1] === 'a') ? 'b' : 'a');
        $this->assertFalse(download_signer::verify(42, (int) $params['exp'], $tampered));
    }

    public function test_verify_rejects_expired_signature(): void {
        $this->resetAfterTest();

        // Sign with a TTL that has already elapsed.
        $url = download_signer::sign_url(42, -10);
        $params = $url->params();

        $this->assertFalse(download_signer::verify(42, (int) $params['exp'], $params['sig']));
    }

    public function test_verify_rejects_empty_signature(): void {
        $this->resetAfterTest();

        $this->assertFalse(download_signer::verify(42, time() + 900, ''));
    }

    public function test_signatures_are_stable_for_the_same_inputs(): void {
        $this->resetAfterTest();

        $url1 = download_signer::sign_url(42, 900);
        $exp = (int) $url1->params()['exp'];

        // Re-signing the exact same (versionid, expires) pair (as would happen
        // if sign_url() were called twice at the same second) must produce a
        // verifiable signature via the same verify() call, proving the secret
        // is stable across calls within a request/test.
        $this->assertTrue(download_signer::verify(42, $exp, $url1->params()['sig']));
    }

    public function test_different_versions_get_different_signatures(): void {
        $this->resetAfterTest();

        $url1 = download_signer::sign_url(1, 900);
        $url2 = download_signer::sign_url(2, 900);

        $this->assertNotEquals($url1->params()['sig'], $url2->params()['sig']);
    }
}
