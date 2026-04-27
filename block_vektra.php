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
 * Block vektra - RAG chatbot widget for courses.
 *
 * Injects the Vektra chatbot widget into course pages. The widget provides
 * AI-powered Q&A over course materials using Retrieval-Augmented Generation.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block vektra class — injects the Vektra chatbot widget into course pages.
 */
class block_vektra extends block_base {
    /** @var int Safety margin in seconds to avoid serving about-to-expire tokens. */
    private const TOKEN_EXPIRY_MARGIN_SECONDS = 300;

    /**
     * Initialize the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_vektra');
    }

    /**
     * Allow only one instance per course.
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Block is only meaningful in course context.
     */
    public function applicable_formats() {
        return [
            'course-view' => true,
            'site'        => false,
            'my'          => false,
        ];
    }

    /**
     * Enable global configuration.
     */
    public function has_config() {
        return true;
    }

    /**
     * Provide instance-specific configuration fields.
     */
    public function specialization() {
        if (!empty($this->config?->title)) {
            $this->title = $this->config->title;
        } else {
            // Fall back to a localized course-aware default when no override is set.
            $coursename = $this->page->course->fullname ?? '';
            $this->title = get_string('default_title', 'block_vektra', $coursename);
        }
    }

    /**
     * Persist instance config and best-effort PATCH the Vektra namespace config.
     *
     * The local configdata is always saved via the parent. Behavioral fields
     * (grounding_mode, show_sources) are not stored locally — they are pushed
     * to the Vektra backend, which is the single source of truth. PATCH errors
     * are surfaced as warnings but do not abort the save.
     *
     * @param object $data Submitted form data with config_ prefix already stripped.
     * @param bool $nolongerused Unused parameter kept for parent signature compatibility.
     */
    public function instance_config_save($data, $nolongerused = false) {
        // Capture transient and backend-only fields, then strip them from $data so
        // the parent does not serialize them into configdata. Behavioral fields live
        // on the Vektra backend; the form-open marker is per-request only.
        $getok       = (int) ($data->get_ok ?? 0);
        $grounding   = $data->grounding_mode ?? 'inherit';
        $showsources = $data->show_sources_choice ?? 'inherit';
        unset($data->get_ok, $data->grounding_mode, $data->show_sources_choice);

        // Always persist configdata first so the form save itself never fails.
        parent::instance_config_save($data, $nolongerused);

        // If the form-open GET did not succeed, the teacher could not see the real
        // backend state, so the values they submitted for the behavioral selects are
        // not trustworthy. Skip the PATCH entirely to avoid silently clobbering
        // existing namespace overrides.
        if ($getok !== 1) {
            \core\notification::info(
                get_string('save_info_behavioral_skipped', 'block_vektra')
            );
            return;
        }

        $payload = [];

        if ($grounding === 'inherit') {
            $payload['grounding_mode'] = null;
        } else if (in_array($grounding, ['strict', 'hybrid'], true)) {
            $payload['grounding_mode'] = $grounding;
        }

        if ($showsources === 'inherit') {
            $payload['show_sources'] = null;
        } else if ($showsources === 'yes') {
            $payload['show_sources'] = true;
        } else if ($showsources === 'no') {
            $payload['show_sources'] = false;
        }

        if (empty($payload)) {
            return;
        }

        $apiurl = get_config('block_vektra', 'apiurl');
        $apikey = get_config('block_vektra', 'apikey');
        if (empty($apiurl) || empty($apikey)) {
            \core\notification::warning(
                get_string('save_warning_not_configured', 'block_vektra')
            );
            return;
        }

        // Resolve namespace via the shared chain (explicit override > course_id
        // override > course shortname) so admin GET/PATCH targets the same
        // identifier the backend uses on the JWT path.
        $namespace = \block_vektra\namespace_resolver::resolve($data, $this->page->course);
        if ($namespace === '') {
            \core\notification::warning(
                get_string('save_warning_no_namespace', 'block_vektra')
            );
            return;
        }

        $client = new \block_vektra\vektra_client($apiurl, $apikey);
        $result = $client->patch_namespace_config($namespace, $payload);

        if (empty($result['ok'])) {
            $a = (object) [
                'message' => $result['message'] ?? '',
                'code'    => $result['error_code'] ?? '',
            ];
            \core\notification::warning(
                get_string('save_warning_patch_failed', 'block_vektra', $a)
            );
        }
    }

    /**
     * Render the block content.
     *
     * The block itself is invisible — it only injects the chatbot widget
     * script tag into the page footer. The widget renders as a floating
     * chat button in the bottom-right corner.
     */
    public function get_content() {
        global $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Only show for users with the usechatbot capability (enrolled students, teachers).
        if (!has_capability('block/vektra:usechatbot', $this->context)) {
            return $this->content;
        }

        $apiurl = get_config('block_vektra', 'apiurl');
        $apikey = get_config('block_vektra', 'apikey');

        if (empty($apiurl) || empty($apikey)) {
            if (has_capability('moodle/site:config', context_system::instance())) {
                $this->content->text = get_string('notconfigured', 'block_vektra');
            }
            return $this->content;
        }

        $course = $this->page->course;

        // Determine course_id via the shared resolver (override > shortname; '0'-safe).
        $courseid = \block_vektra\namespace_resolver::resolve_course_id($this->config, $course);

        // Determine namespace: explicit config only, or null to let the API
        // default to course_id on the JWT path.
        $ns = $this->config?->namespace;
        $namespace = (is_string($ns) && $ns !== '') ? $ns : null;

        // Determine widget options from instance config or global defaults.
        $theme = !empty($this->config?->theme)
            ? $this->config->theme
            : get_config('block_vektra', 'default_theme');
        $language = !empty($this->config?->language)
            ? $this->config->language
            : current_language();

        // Get or generate a JWT token, cached in session to avoid repeated API calls.
        $token = $this->get_cached_token($USER->username, $courseid, $apiurl, $apikey, $namespace);

        if ($token === null) {
            if (has_capability('moodle/site:config', context_system::instance())) {
                $this->content->text = get_string('tokenerror', 'block_vektra');
            }
            return $this->content;
        }

        // Use public URL for browser-side resources (widget JS + API calls).
        // Falls back to API URL when not set (same host serves both).
        $publicurl = get_config('block_vektra', 'publicurl');
        if (empty($publicurl)) {
            $publicurl = $apiurl;
        }

        // Inject widget script via Moodle's page API (renders in footer).
        $widgeturl = rtrim($publicurl, '/') . '/static/learn/vektra-chat.js';

        // Build token refresh URL with sesskey for CSRF protection.
        $refreshurl = new \moodle_url('/blocks/vektra/ajax.php', [
            'id'       => $this->instance->id,
            'courseid' => $course->id,
            'sesskey'  => sesskey(),
        ]);

        // Title: prefer instance override, otherwise the course-aware localized default.
        $widgettitle = !empty($this->config?->title)
            ? (string) $this->config->title
            : get_string('default_title', 'block_vektra', $course->fullname ?? '');

        $attributes = [
            'src'                    => $widgeturl,
            'data-api-url'           => $publicurl,
            'data-course-id'         => $courseid,
            'data-token'             => $token,
            'data-token-refresh-url' => $refreshurl->out(false),
            'data-title'             => $widgettitle,
        ];
        if (!empty($theme)) {
            $attributes['data-theme'] = $theme;
        }
        if (!empty($language)) {
            $attributes['data-language'] = $language;
        }

        // Plugin-global visual brand (no per-course override by design).
        $primarycolor = get_config('block_vektra', 'default_primary_color');
        if (!empty($primarycolor)) {
            $attributes['data-primary-color'] = $primarycolor;
        }
        $logourl = get_config('block_vektra', 'default_logo_url');
        if (!empty($logourl)) {
            $attributes['data-icon'] = $logourl;
        }

        // Per-instance welcome message (no admin default).
        if (!empty($this->config?->welcome_message)) {
            $attributes['data-welcome-message'] = (string) $this->config->welcome_message;
        }

        // Plugin-global attribution (visible by default; emit only when configured).
        $poweredbytext = get_config('block_vektra', 'powered_by_text');
        if (!empty($poweredbytext)) {
            $attributes['data-powered-by-text'] = $poweredbytext;
        }
        $poweredbyurl = get_config('block_vektra', 'powered_by_url');
        if (!empty($poweredbyurl)) {
            $attributes['data-powered-by-url'] = $poweredbyurl;
        }

        // Inject widget via js_init_code. Use json_encode for safe JS escaping.
        $jscode = "var s=document.createElement('script');";
        foreach ($attributes as $key => $value) {
            $jscode .= "s.setAttribute(" . json_encode($key) . ","
                     . json_encode($value) . ");";
        }
        $jscode .= "document.body.appendChild(s);";

        $this->page->requires->js_init_code($jscode, false);

        // Block content is empty — widget floats independently.
        $this->content->text = get_string('widgetactive', 'block_vektra');

        return $this->content;
    }

    /**
     * Return a cached token from the user session, or generate a new one.
     *
     * Tokens are cached per student+course using the server-provided expiry,
     * with a 5-minute safety margin to avoid serving about-to-expire tokens.
     *
     * @param string $username Moodle username.
     * @param string $courseid Vektra course identifier.
     * @param string $apiurl Vektra API base URL.
     * @param string $apikey Vektra API key.
     * @param string|null $namespace Optional namespace override for the JWT.
     * @return string|null JWT token or null on failure.
     */
    private function get_cached_token(
        string $username,
        string $courseid,
        string $apiurl,
        string $apikey,
        ?string $namespace = null,
    ): ?string {
        global $SESSION;

        $cachekey = 'block_vektra_' . sha1(
            $apiurl . '|' . hash('sha256', $apikey) . '|' . $username . '|' . $courseid
            . '|' . ($namespace ?? '')
        );

        // Check session cache: token + expiry timestamp.
        if (
            isset($SESSION->{$cachekey}) &&
            is_array($SESSION->{$cachekey}) &&
            isset($SESSION->{$cachekey}['expires_at'], $SESSION->{$cachekey}['token']) &&
            $SESSION->{$cachekey}['expires_at'] > time() + self::TOKEN_EXPIRY_MARGIN_SECONDS
        ) {
            return $SESSION->{$cachekey}['token'];
        }

        // Generate a fresh token.
        $client = new \block_vektra\vektra_client($apiurl, $apikey);
        $result = $client->generate_token($username, $courseid, $namespace);

        if ($result !== null) {
            $SESSION->{$cachekey} = [
                'token'      => $result['token'],
                'expires_at' => $result['expires_at'],
            ];
            return $result['token'];
        }

        return null;
    }
}
