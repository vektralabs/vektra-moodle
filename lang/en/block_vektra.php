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

$string['pluginname'] = 'Vektra AI Assistant';
$string['vektra:addinstance'] = 'Add a Vektra AI Assistant block';
$string['vektra:usechatbot'] = 'Use the Vektra AI chatbot';

// Block content.
$string['widgetactive'] = 'AI Assistant is active. Look for the chat button in the bottom-right corner.';
$string['notconfigured'] = 'Vektra is not configured. Go to Site administration > Plugins > Blocks > Vektra AI Assistant.';
$string['tokenerror'] = 'Could not connect to Vektra API. Check the API URL and key in plugin settings.';

// Global settings.
$string['settings_connection'] = 'Connection';
$string['settings_connection_desc'] = 'Configure the connection to your Vektra instance.';
$string['settings_apiurl'] = 'Vektra API URL';
$string['settings_apiurl_desc'] = 'Base URL of your Vektra instance (e.g., https://vektra.example.com). Do not include a trailing slash.';
$string['settings_publicurl'] = 'Public URL (browser)';
$string['settings_publicurl_desc'] = 'URL accessible from the user\'s browser for loading the widget and making queries. Leave empty if the API URL is already browser-accessible. Only needed when the server-side API URL differs (e.g., Docker internal hostname).';
$string['settings_apikey'] = 'API Key';
$string['settings_apikey_desc'] = 'Vektra API key with admin scope. Required for generating student tokens.';
$string['settings_widget'] = 'Widget defaults';
$string['settings_widget_desc'] = 'Default appearance settings for the chatbot widget. These can be overridden per course.';
$string['settings_theme'] = 'Default theme';
$string['settings_theme_desc'] = 'Default color theme for the chatbot widget.';
$string['theme_light'] = 'Light';
$string['theme_dark'] = 'Dark';

// Branding (plugin-global; no per-course override).
$string['settings_branding'] = 'Branding';
$string['settings_branding_desc'] = 'Visual brand applied to the chatbot widget across all courses.';
$string['settings_primary_color'] = 'Primary color';
$string['settings_primary_color_desc'] = 'Primary color used by the widget (e.g., #3366cc). Leave empty for the widget default.';
$string['settings_logo_url'] = 'Widget logo URL';
$string['settings_logo_url_desc'] = 'URL of an icon image displayed in the widget header. Leave empty for the widget default.';

// Attribution (plugin-global; visible by default).
$string['settings_attribution'] = 'Attribution';
$string['settings_attribution_desc'] = 'Powered-by attribution shown in the widget.';
$string['settings_powered_by_text'] = 'Attribution text';
$string['settings_powered_by_text_desc'] = 'Custom "powered by" text shown in the widget. Leave empty to keep the default.';
$string['settings_powered_by_url'] = 'Attribution link';
$string['settings_powered_by_url_desc'] = 'Optional URL the attribution text links to.';

// Instance settings.
$string['config_title'] = 'Block title';
$string['config_course_id'] = 'Vektra course ID';
$string['config_course_id_help'] = 'The course identifier in Vektra. Leave empty to use the Moodle course short name (which is automatically slugified to match the n8n ingestion algorithm). When set explicitly, this value is sent to Vektra as-is and must match the Vektra namespace charset [0-9a-zA-Z_-]; otherwise queries silently return no results. See n8n/README.md "Namespace Convention" for the slug algorithm.';
$string['config_namespace'] = 'Vektra namespace';
$string['config_namespace_help'] = 'Override the namespace included in the JWT token (max 64 characters). Leave empty to let the chain default to the course_id (or the slugified course short name). When set explicitly, this value is sent to Vektra as-is and must match the Vektra namespace charset [0-9a-zA-Z_-]; otherwise queries silently return no results.';
$string['config_theme'] = 'Theme';
$string['config_language'] = 'Language';
$string['config_language_help'] = 'Override the widget language (e.g., "en", "it"). Leave empty to use the current Moodle language.';
$string['config_welcome_message'] = 'Welcome message';
$string['config_welcome_message_help'] = 'Optional greeting shown when the chat opens. Leave empty for the widget default.';
$string['usedefault'] = 'Use default';

// Behavioral instance settings (saved to Vektra backend, not Moodle configdata).
$string['config_behavioral_header'] = 'Behavior (Vektra)';
$string['config_inherit'] = 'Inherit';
$string['config_grounding_mode'] = 'Grounding mode';
$string['config_grounding_mode_help'] = 'How strictly the assistant must stay within course materials. "Inherit" uses the namespace default.';
$string['config_grounding_strict'] = 'Strict';
$string['config_grounding_hybrid'] = 'Hybrid';
$string['config_show_sources_choice'] = 'Show sources';
$string['config_show_sources_choice_help'] = 'Whether the widget shows source citations beneath answers. "Inherit" uses the namespace default.';
$string['config_show_sources_yes'] = 'Yes';
$string['config_show_sources_no'] = 'No';
$string['config_effective_label'] = 'Effective: {$a->value} ({$a->status})';
$string['config_status_default'] = 'default';
$string['config_status_override'] = 'override';
$string['config_value_unknown'] = 'unknown';
$string['config_namespace_unavailable'] = 'Could not load the current Vektra configuration. Saved values will still be applied.';

// Save warnings (best-effort PATCH).
$string['save_warning_not_configured'] = 'Vektra API is not configured; behavioral settings could not be sent to the backend.';
$string['save_warning_no_namespace'] = 'Could not resolve the namespace; behavioral settings could not be sent to the backend.';
$string['save_warning_patch_failed'] = 'Could not save behavioral settings to Vektra: {$a->message} ({$a->code})';
$string['save_info_behavioral_skipped'] = 'Vektra was unreachable when this form was opened, so behavioral settings were not sent to the backend. Reopen the form to make changes.';

// Default block title (used when no instance override is set).
$string['default_title'] = 'Assistant for {$a}';

// Errors.
$string['invalidblockinstance'] = 'Invalid block instance for this course.';

// Privacy.
$string['privacy:metadata'] = 'The Vektra AI Assistant block does not store any personal data. Tokens are generated via the external Vektra API and cached only in the PHP session.';
