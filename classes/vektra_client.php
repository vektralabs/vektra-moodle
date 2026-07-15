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

    /** @var array{httpcode: int, code: string, message: string}|null Details of the last generate_token failure. */
    private ?array $lasttokenerror = null;

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
        $this->lasttokenerror = null;
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
            [$errorcode, $message] = $this->parse_error_envelope($response, $httpcode);
            if ($httpcode === 0) {
                $message = 'Connection failed or timed out';
            }
            $this->lasttokenerror = [
                'httpcode' => $httpcode,
                'code'     => $errorcode ?? "HTTP {$httpcode}",
                'message'  => $this->redact($message),
            ];
            debugging(
                $this->redact("Vektra token generation failed: HTTP {$httpcode} - {$response}"),
                DEBUG_DEVELOPER
            );
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['token'])) {
            $this->lasttokenerror = [
                'httpcode' => $httpcode,
                'code'     => "HTTP {$httpcode}",
                'message'  => 'Malformed token response from the Vektra API',
            ];
            debugging(
                $this->redact('Vektra token response missing "token" field: ' . $response),
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
     * Details of the last generate_token() failure, for diagnostic display.
     *
     * The message is already redacted (no API keys, JWTs, or auth headers)
     * so callers can surface it to privileged users as-is.
     *
     * @return array{httpcode: int, code: string, message: string}|null Null when the last call succeeded.
     */
    public function get_last_token_error(): ?array {
        return $this->lasttokenerror;
    }

    /**
     * Strip secrets from text destined for logs or on-screen diagnostics.
     *
     * Redacts the configured API key, any Authorization bearer value, and
     * JWT-shaped strings (three dot-separated base64url segments).
     *
     * @param string $text Raw text (e.g., an HTTP response body).
     * @return string Text with secrets replaced by [REDACTED].
     */
    private function redact(string $text): string {
        if ($this->apikey !== '') {
            $text = str_replace($this->apikey, '[REDACTED]', $text);
        }
        $text = preg_replace('/Bearer\s+\S+/', 'Bearer [REDACTED]', $text);
        $text = preg_replace(
            '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',
            '[REDACTED]',
            $text
        );
        return $text;
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
                $this->redact("Vektra get_namespace_config failed: HTTP {$httpcode} - {$response}"),
                DEBUG_DEVELOPER
            );
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['config']) || !isset($data['resolved'])) {
            debugging(
                $this->redact('Vektra namespace config response missing expected fields: ' . $response),
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
            $this->redact("Vektra patch_namespace_config failed: HTTP {$httpcode} - {$response}"),
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
     * The platform returns the REQ-010 envelope at the document root:
     * `{"error": {"code": ..., "message": ...}}`. Older backends nested it under
     * `detail` (`{"detail": {"error": {...}}}`, a FastAPI HTTPException artifact
     * fixed in DEBT-034); that shape is still accepted as a fallback. FastAPI
     * validation errors are `{"detail": [{"msg": ..., "loc": [...]}]}` and a few
     * plain handlers emit `{"detail": "<string>"}`. This helper covers all of
     * them and falls back to `HTTP <code>` when nothing parseable is found.
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

        // Standard Vektra structured envelope at the document root (DEBT-034).
        if (isset($data['error']) && is_array($data['error'])) {
            return $this->extract_error_object($data['error'], $message);
        }

        $detail = $data['detail'] ?? null;

        // Legacy: the same envelope nested under `detail` (pre-DEBT-034 backends).
        if (is_array($detail) && isset($detail['error']) && is_array($detail['error'])) {
            return $this->extract_error_object($detail['error'], $message);
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
            // Unknown nested shape — surface compactly, but only when there is
            // something useful to show. An empty array/object would otherwise
            // collapse the friendly "HTTP {code}" fallback into "[]" or "{}".
            $encoded = json_encode($detail);
            if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
                $message = $encoded;
            }
        }

        return [$errorcode, $message];
    }

    /**
     * Pull (code, message) out of a REQ-010 `error` object.
     *
     * @param array $err the decoded `error` object (`{"code": ..., "message": ...}`)
     * @param string $fallback message kept when `error.message` is absent
     * @return array{0: string|null, 1: string} [error_code, human-readable message]
     */
    private function extract_error_object(array $err, string $fallback): array {
        $errorcode = null;
        $message   = $fallback;
        // Stricter than !empty(): a literal '0' code or message is a real value
        // (the same rule the namespace resolver follows).
        if (isset($err['code']) && is_string($err['code']) && $err['code'] !== '') {
            $errorcode = $err['code'];
        }
        if (isset($err['message']) && is_string($err['message']) && $err['message'] !== '') {
            $message = $err['message'];
        }
        return [$errorcode, $message];
    }
}
