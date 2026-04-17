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
 * Version information for block_vektra (Moodle 4.3 test override).
 *
 * Override del file version.php ufficiale, usato via bind-mount nel
 * docker-compose.yml di questa cartella (test Moodle 4.3.3).
 * Il file della root resta dichiarato per 5.1 only.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_vektra';
$plugin->version   = 2026032200;
$plugin->requires  = 2023100900;   // Moodle 4.3.
$plugin->supported = [403, 501];   // Range esteso: 4.3 -> 5.1 (solo per test).
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.3.0';
