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
 * Global settings for block_vektra.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Connection settings.
    $settings->add(new admin_setting_heading(
        'block_vektra/connection',
        get_string('settings_connection', 'block_vektra'),
        get_string('settings_connection_desc', 'block_vektra')
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/apiurl',
        get_string('settings_apiurl', 'block_vektra'),
        get_string('settings_apiurl_desc', 'block_vektra'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/publicurl',
        get_string('settings_publicurl', 'block_vektra'),
        get_string('settings_publicurl_desc', 'block_vektra'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_vektra/apikey',
        get_string('settings_apikey', 'block_vektra'),
        get_string('settings_apikey_desc', 'block_vektra'),
        ''
    ));

    // Widget defaults.
    $settings->add(new admin_setting_heading(
        'block_vektra/widget',
        get_string('settings_widget', 'block_vektra'),
        get_string('settings_widget_desc', 'block_vektra')
    ));

    $settings->add(new admin_setting_configselect(
        'block_vektra/default_theme',
        get_string('settings_theme', 'block_vektra'),
        get_string('settings_theme_desc', 'block_vektra'),
        'light',
        [
            'light' => get_string('theme_light', 'block_vektra'),
            'dark'  => get_string('theme_dark', 'block_vektra'),
        ]
    ));

    // Branding (plugin-global; no per-course override by design).
    $settings->add(new admin_setting_heading(
        'block_vektra/branding',
        get_string('settings_branding', 'block_vektra'),
        get_string('settings_branding_desc', 'block_vektra')
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/default_primary_color',
        get_string('settings_primary_color', 'block_vektra'),
        get_string('settings_primary_color_desc', 'block_vektra'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/default_logo_url',
        get_string('settings_logo_url', 'block_vektra'),
        get_string('settings_logo_url_desc', 'block_vektra'),
        '',
        PARAM_URL
    ));

    // Attribution (plugin-global; visible by default in widget).
    $settings->add(new admin_setting_heading(
        'block_vektra/attribution',
        get_string('settings_attribution', 'block_vektra'),
        get_string('settings_attribution_desc', 'block_vektra')
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/powered_by_text',
        get_string('settings_powered_by_text', 'block_vektra'),
        get_string('settings_powered_by_text_desc', 'block_vektra'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_vektra/powered_by_url',
        get_string('settings_powered_by_url', 'block_vektra'),
        get_string('settings_powered_by_url_desc', 'block_vektra'),
        '',
        PARAM_URL
    ));

    // Behavioral defaults (apply when a course-instance leaves the field on "inherit").
    $settings->add(new admin_setting_heading(
        'block_vektra/behavior_defaults',
        get_string('settings_behavior_defaults', 'block_vektra'),
        get_string('settings_behavior_defaults_desc', 'block_vektra')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_vektra/default_show_sources',
        get_string('settings_default_show_sources', 'block_vektra'),
        get_string('settings_default_show_sources_desc', 'block_vektra'),
        1
    ));
}
