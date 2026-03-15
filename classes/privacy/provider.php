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
 * Privacy subsystem implementation for block_vektra.
 *
 * This plugin does not store any personal data in Moodle. Tokens are
 * generated server-side via the Vektra API and cached only in the
 * PHP session (not persisted to database).
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_vektra\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider — declares that this plugin does not store user data.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Returns the reason this plugin collects no data.
     *
     * @return string The language string identifier for the reason.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
