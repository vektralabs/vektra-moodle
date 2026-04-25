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
 * Vektra API client.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_vektra;

/**
 * HTTP client for the Vektra Learn and admin APIs.
 *
 * Wraps the calls used by the Moodle plugin: JWT token generation for the
 * widget, plus GET/PATCH on the namespace configuration endpoint that backs
 * the per-course behavioral settings.
 *
 * Uses Moodle's curl wrapper for HTTP requests; all methods return values
 * (or null) instead of throwing, so callers can degrade gracefully.
 */
class vektra_client {
    /** @var int Fallback token expiry in seconds when the server does not provide one. */
    private const DEFAULT_TOKEN_FALLBACK_EXPIRY_SECONDS = 900;

    /** @var string Vektra API base URL. */
    private string $apiurl;

    /** @var string Vektra API key (admin scope required for token generation). */
    private string $apikey;

    /**
     * Constructor.
     *
     * @param string $apiurl Vektra API base URL (e.g., https://vektra.example.com).
     * @param string $apikey API key with admin scope.
     */
    public function __construct(string $apiurl, string $apikey) {
        $this->apiurl = rtrim($apiurl, '/');
        $this->apikey = $apikey;
    }

    /**
     * Generate a JWT dashboard token for a student+course pair.
     *
     * Calls POST /api/v1/learn/tokens on the Vektra API.
     * Returns both the token string and the server-provided expiry timestamp
     * so callers can cache accurately.
     *
     * @param string $studentid Student identifier (Moodle username).
     * @param string $courseid Course identifier.
     * @param string|null $namespace Optional namespace override (max 64 chars). When omitted, the API uses course_id.
     * @return array{token: string, expires_at: int}|null Token data, or null on failure.
     */
    public function generate_token(string $studentid, string $courseid, ?string $namespace = null): ?array {
        $url = $this->apiurl . '/api/v1/learn/tokens';

        $body = [
            'student_id' => $studentid,
            'course_id'  => $courseid,
        ];
        if ($namespace !== null && $namespace !== '') {
            $body['namespace'] = $namespace;
        }
        $payload = json_encode($body);

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ]);
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

        // Parse expires_at from ISO 8601 response, fallback to short TTL.
        $expiresat = time() + self::DEFAULT_TOKEN_FALLBACK_EXPIRY_SECONDS;
        if (!empty($data['expires_at'])) {
            $parsed = strtotime($data['expires_at']);
            if ($parsed !== false) {
                $expiresat = $parsed;
            }
        }

        return [
            'token'      => $data['token'],
            'expires_at' => $expiresat,
        ];
    }

    /**
     * Fetch the namespace configuration (raw + resolved) from the Vektra API.
     *
     * Calls GET /api/v1/admin/namespaces/{namespace}/config.
     * Returns ['config' => [...], 'resolved' => [...]] on success, null on any failure.
     * Failures are logged via debugging() but never thrown.
     *
     * @param string $namespace Namespace identifier.
     * @param int $timeout Total cURL timeout in seconds (default 5; pass 2 from form context).
     * @return array{config: array, resolved: array}|null
     */
    public function get_namespace_config(string $namespace, int $timeout = 5): ?array {
        if ($namespace === '') {
            return null;
        }

        $url = $this->apiurl . '/api/v1/admin/namespaces/' . rawurlencode($namespace) . '/config';

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min($timeout, 3),
        ]);
        $curl->setHeader([
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);

        $response = $curl->get($url);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode !== 200) {
            debugging(
                "Vektra get_namespace_config failed: HTTP {$httpcode} - {$response}",
                DEBUG_DEVELOPER
            );
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['config']) || !isset($data['resolved'])) {
            debugging(
                'Vektra namespace config response missing expected fields: ' . $response,
                DEBUG_DEVELOPER
            );
            return null;
        }

        return [
            'config'   => is_array($data['config']) ? $data['config'] : [],
            'resolved' => is_array($data['resolved']) ? $data['resolved'] : [],
        ];
    }

    /**
     * Patch the namespace configuration on the Vektra API.
     *
     * Calls PATCH /api/v1/admin/namespaces/{namespace}/config with the whitelisted
     * payload (grounding_mode, show_sources). On HTTP 2xx returns ['ok' => true].
     * On any failure returns ['ok' => false, 'error_code' => string|null, 'message' => string].
     * Never throws.
     *
     * @param string $namespace Namespace identifier.
     * @param array $payload Whitelisted config keys (grounding_mode, show_sources).
     * @return array{ok: bool, error_code?: string|null, message?: string}
     */
    public function patch_namespace_config(string $namespace, array $payload): array {
        if ($namespace === '') {
            return ['ok' => false, 'error_code' => null, 'message' => 'empty namespace'];
        }

        $url = $this->apiurl . '/api/v1/admin/namespaces/' . rawurlencode($namespace) . '/config';
        $body = json_encode($payload);

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);

        $response = $curl->patch($url, $body);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode >= 200 && $httpcode < 300) {
            return ['ok' => true];
        }

        [$errorcode, $message] = $this->parse_error_envelope($response, $httpcode);

        debugging(
            "Vektra patch_namespace_config failed: HTTP {$httpcode} - {$response}",
            DEBUG_DEVELOPER
        );

        return [
            'ok'         => false,
            'error_code' => $errorcode,
            'message'    => $message,
        ];
    }

    /**
     * Extract a (code, message) pair from a Vektra error response body.
     *
     * The platform wraps errors as `{"detail": {"error": {"code": ..., "message": ...}}}`.
     * FastAPI validation errors are `{"detail": [{"msg": ..., "loc": [...]}]}` and a few
     * plain handlers still emit `{"detail": "<string>"}`. This helper covers all three
     * shapes and falls back to `HTTP <code>` when nothing parseable is found.
     *
     * @return array{0: string|null, 1: string} [error_code, human-readable message]
     */
    private function parse_error_envelope(string $response, int $httpcode): array {
        $errorcode = null;
        $message   = "HTTP {$httpcode}";

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [$errorcode, $message];
        }

        $detail = $data['detail'] ?? null;

        if (is_array($detail) && isset($detail['error']) && is_array($detail['error'])) {
            // Standard Vektra structured envelope.
            $err = $detail['error'];
            if (!empty($err['code'])) {
                $errorcode = (string) $err['code'];
            }
            if (!empty($err['message'])) {
                $message = (string) $err['message'];
            }
            return [$errorcode, $message];
        }

        if (is_string($detail) && $detail !== '') {
            $message = $detail;
            return [$errorcode, $message];
        }

        if (is_array($detail)) {
            // FastAPI validation error: list of {msg, loc, type}.
            $first = reset($detail);
            if (is_array($first) && !empty($first['msg'])) {
                $message = (string) $first['msg'];
                return [$errorcode, $message];
            }
            // Unknown nested shape — surface compactly.
            $message = json_encode($detail);
        }

        return [$errorcode, $message];
    }
}
