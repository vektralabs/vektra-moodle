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
 * Per-instance configuration form for block_vektra.
 *
 * Allows teachers to override the course_id mapping, theme, and language
 * on a per-course basis.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Per-instance configuration form for block_vektra.
 */
class block_vektra_edit_form extends block_edit_form {
    /**
     * Add form fields for per-instance configuration.
     *
     * @param MoodleQuickForm $mform The form being built.
     */
    protected function specific_definition($mform) {
        $mform->addElement(
            'header',
            'vektraheader',
            get_string('blocksettings', 'block')
        );

        // Block title override (empty = use plugin name).
        $mform->addElement(
            'text',
            'config_title',
            get_string('config_title', 'block_vektra')
        );
        $mform->setType('config_title', PARAM_TEXT);

        // Vektra course_id override (defaults to Moodle course shortname).
        $mform->addElement(
            'text',
            'config_course_id',
            get_string('config_course_id', 'block_vektra')
        );
        $mform->setType('config_course_id', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('config_course_id', 'config_course_id', 'block_vektra');

        // Vektra namespace override (defaults to course_id on the API side).
        $mform->addElement(
            'text',
            'config_namespace',
            get_string('config_namespace', 'block_vektra')
        );
        $mform->setType('config_namespace', PARAM_ALPHANUMEXT);
        $mform->addRule('config_namespace', get_string('maximumchars', '', 64), 'maxlength', 64, 'client');
        $mform->addHelpButton('config_namespace', 'config_namespace', 'block_vektra');

        // Theme override.
        $mform->addElement(
            'select',
            'config_theme',
            get_string('config_theme', 'block_vektra'),
            [
                ''      => get_string('usedefault', 'block_vektra'),
                'light' => get_string('theme_light', 'block_vektra'),
                'dark'  => get_string('theme_dark', 'block_vektra'),
            ]
        );

        // Language override.
        $mform->addElement(
            'text',
            'config_language',
            get_string('config_language', 'block_vektra')
        );
        $mform->setType('config_language', PARAM_ALPHA);
        $mform->addHelpButton('config_language', 'config_language', 'block_vektra');
    }
}
