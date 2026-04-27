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
 * Resolver for the effective Vektra namespace and course identifier of a block instance.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_vektra;

/**
 * Single source of truth for the namespace / course_id resolution chains.
 *
 * Three call sites in the plugin previously duplicated this logic with `!empty()`,
 * which silently treated the literal string `'0'` (a valid `PARAM_ALPHANUMEXT`
 * value) as empty. Centralizing here keeps the chains in lockstep with the
 * Vektra backend's default-chain semantics on the JWT path.
 */
class namespace_resolver {
    /**
     * Resolve the effective Vektra namespace for a block instance.
     *
     * Mirrors the backend default chain: explicit namespace override >
     * course_id override > course shortname. Uses `is_string($x) && $x !== ''`
     * so the literal `'0'` is preserved through the chain.
     *
     * @param object|null $config Block instance config (e.g. `$block->config`).
     *                            Inspected for `->namespace` and `->course_id`.
     * @param object|null $course Moodle course record (inspected for `->shortname`).
     * @return string Resolved namespace, or empty string if nothing resolvable.
     */
    public static function resolve(?object $config, ?object $course): string {
        $ns = $config?->namespace ?? null;
        if (is_string($ns) && $ns !== '') {
            return $ns;
        }
        // Tail of the namespace chain matches the course_id chain exactly.
        return self::resolve_course_id($config, $course);
    }

    /**
     * Resolve the effective course identifier for the widget `data-course-id`.
     *
     * Chain: explicit course_id override > course shortname. Same `'0'`-safe
     * check as `resolve()`.
     *
     * @param object|null $config Block instance config.
     * @param object|null $course Moodle course record.
     * @return string Resolved course_id, or empty string if nothing resolvable.
     */
    public static function resolve_course_id(?object $config, ?object $course): string {
        $cid = $config?->course_id ?? null;
        if (is_string($cid) && $cid !== '') {
            return $cid;
        }
        $sn = $course?->shortname ?? null;
        if (is_string($sn) && $sn !== '') {
            return $sn;
        }
        return '';
    }
}
