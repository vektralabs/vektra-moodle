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
 * Upgrade steps for block_vektra.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute block_vektra upgrade steps from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_vektra_upgrade($oldversion) {
    if ($oldversion < 2026042500) {
        // Seed the new admin default for show_sources so existing installs have a
        // sensible value before the settings page is visited.
        if (get_config('block_vektra', 'default_show_sources') === false) {
            set_config('default_show_sources', 1, 'block_vektra');
        }

        upgrade_block_savepoint(true, 2026042500, 'vektra');
    }

    return true;
}
