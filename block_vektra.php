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

defined('MOODLE_INTERNAL') || die();

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
        global $USER, $COURSE, $PAGE;

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

        // Determine course_id: use per-instance override or Moodle shortname.
        $courseid = !empty($this->config?->course_id)
            ? $this->config->course_id
            : $COURSE->shortname;

        // Determine widget options from instance config or global defaults.
        $theme = !empty($this->config?->theme)
            ? $this->config->theme
            : get_config('block_vektra', 'default_theme');
        $language = !empty($this->config?->language)
            ? $this->config->language
            : current_language();

        // Get or generate a JWT token, cached in the user session to avoid
        // an API call on every page load.
        $token = $this->get_cached_token($USER->username, $courseid, $apiurl, $apikey);

        if ($token === null) {
            if (has_capability('moodle/site:config', context_system::instance())) {
                $this->content->text = get_string('tokenerror', 'block_vektra');
            }
            return $this->content;
        }

        // Inject widget script via Moodle's page API (renders in footer).
        $widgeturl = rtrim($apiurl, '/') . '/static/vektra-chat.js';

        $attributes = [
            'src'            => $widgeturl,
            'data-api-url'   => $apiurl,
            'data-course-id' => $courseid,
            'data-token'     => $token,
        ];
        if (!empty($theme)) {
            $attributes['data-theme'] = $theme;
        }
        if (!empty($language)) {
            $attributes['data-language'] = $language;
        }

        // Inject widget via js_init_code. Use json_encode for safe JS escaping.
        $jscode = "var s=document.createElement('script');";
        foreach ($attributes as $key => $value) {
            $jscode .= "s.setAttribute(" . json_encode($key) . ","
                     . json_encode($value) . ");";
        }
        $jscode .= "document.body.appendChild(s);";

        $PAGE->requires->js_init_code($jscode, false);

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
     * @return string|null JWT token or null on failure.
     */
    private function get_cached_token(
        string $username,
        string $courseid,
        string $apiurl,
        string $apikey,
    ): ?string {
        global $SESSION;

        $cachekey = 'block_vektra_' . sha1(
            $apiurl . '|' . hash('sha256', $apikey) . '|' . $username . '|' . $courseid
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
        $result = $client->generate_token($username, $courseid);

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
