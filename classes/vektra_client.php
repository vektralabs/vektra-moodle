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
 * Vektra API client for token generation.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_vektra;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client for the Vektra Learn API.
 *
 * Handles JWT token generation for the chatbot widget.
 * Uses Moodle's curl wrapper for HTTP requests.
 */
class vektra_client {

    /** @var string Vektra API base URL. */
    private string $apiurl;

    /** @var string Vektra API key (admin scope required for token generation). */
    private string $apikey;

    /** @var int Token validity in seconds (default: 8 hours). */
    private int $token_ttl;

    /**
     * Constructor.
     *
     * @param string $apiurl Vektra API base URL (e.g., https://vektra.example.com).
     * @param string $apikey API key with admin scope.
     * @param int $token_ttl Token validity in seconds. Default 28800 (8 hours).
     */
    public function __construct(string $apiurl, string $apikey, int $token_ttl = 28800) {
        $this->apiurl = rtrim($apiurl, '/');
        $this->apikey = $apikey;
        $this->token_ttl = $token_ttl;
    }

    /**
     * Generate a JWT dashboard token for a student+course pair.
     *
     * Calls POST /api/v1/learn/tokens on the Vektra API.
     *
     * @param string $studentid Student identifier (Moodle username).
     * @param string $courseid Course identifier.
     * @return string|null The JWT token string, or null on failure.
     */
    public function generate_token(string $studentid, string $courseid): ?string {
        $url = $this->apiurl . '/api/v1/learn/tokens';

        $payload = json_encode([
            'student_id' => $studentid,
            'course_id'  => $courseid,
            'expires_in' => $this->token_ttl,
        ]);

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);

        $response = $curl->post($url, $payload);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode !== 200 && $httpcode !== 201) {
            debugging(
                "Vektra token generation failed: HTTP {$httpcode} - {$response}",
                DEBUG_DEVELOPER
            );
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['token'])) {
            debugging(
                'Vektra token response missing "token" field: ' . $response,
                DEBUG_DEVELOPER
            );
            return null;
        }

        return $data['token'];
    }
}
