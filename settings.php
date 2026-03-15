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

    // --- Connection settings ---

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

    $settings->add(new admin_setting_configpasswordunmask(
        'block_vektra/apikey',
        get_string('settings_apikey', 'block_vektra'),
        get_string('settings_apikey_desc', 'block_vektra'),
        ''
    ));

    // --- Widget defaults ---

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
}
