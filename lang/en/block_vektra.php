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
 * English language strings for block_vektra.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'AI Tutor';
$string['vektra:addinstance'] = 'Add an AI Tutor block';
$string['vektra:usechatbot'] = 'Use the AI Tutor chatbot';

// Block content.
$string['widgetactive'] = 'AI Assistant is active. Look for the chat button in the bottom-right corner.';
$string['notconfigured'] = 'AI Tutor is not configured. Go to Site administration > Plugins > Blocks > AI Tutor.';
$string['tokenerror'] = 'Could not connect to AI Tutor API. Check the API URL and key in plugin settings.';

// Global settings.
$string['settings_connection'] = 'Connection';
$string['settings_connection_desc'] = 'Configure the connection to your AI Tutor instance.';
$string['settings_apiurl'] = 'AI Tutor API URL';
$string['settings_apiurl_desc'] = 'Base URL of your AI Tutor instance (e.g., https://ai-tutor.example.com). Do not include a trailing slash.';
$string['settings_publicurl'] = 'Public URL (browser)';
$string['settings_publicurl_desc'] = 'URL accessible from the user\'s browser for loading the widget and making queries. Leave empty if the API URL is already browser-accessible. Only needed when the server-side API URL differs (e.g., Docker internal hostname).';
$string['settings_apikey'] = 'API Key';
$string['settings_apikey_desc'] = 'AI Tutor API key with admin scope. Required for generating student tokens.';
$string['settings_widget'] = 'Widget defaults';
$string['settings_widget_desc'] = 'Default appearance settings for the chatbot widget. These can be overridden per course.';
$string['settings_theme'] = 'Default theme';
$string['settings_theme_desc'] = 'Default color theme for the chatbot widget.';
$string['theme_light'] = 'Light';
$string['theme_dark'] = 'Dark';

// Instance settings.
$string['config_title'] = 'Block title';
$string['config_course_id'] = 'AI Tutor course ID';
$string['config_course_id_help'] = 'The course identifier. Leave empty to use the Moodle course short name. Must match the course_id used when ingesting materials into AI Tutor.';
$string['config_namespace'] = 'AI Tutor namespace';
$string['config_namespace_help'] = 'Override the namespace included in the JWT token (max 64 characters). Leave empty to let the API default to the course ID. Useful when multiple courses share the same materials or when the Moodle short name contains characters not valid as a namespace.';
$string['config_theme'] = 'Theme';
$string['config_language'] = 'Language';
$string['config_language_help'] = 'Override the widget language (e.g., "en", "it"). Leave empty to use the current Moodle language.';
$string['usedefault'] = 'Use default';

// Errors.
$string['invalidblockinstance'] = 'Invalid block instance for this course.';

// Privacy.
$string['privacy:metadata'] = 'The AI Tutor block does not store any personal data. Tokens are generated via the external AI Tutor API and cached only in the PHP session.';
