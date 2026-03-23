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
 * AJAX endpoint for token refresh.
 *
 * Called by the vektra-chat.js widget when the JWT expires.
 * Generates a fresh token for the authenticated user and course.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$instanceid = required_param('id', PARAM_INT);

require_login($courseid, false);
require_sesskey();

$context = context_course::instance($courseid);
require_capability('block/vektra:usechatbot', $context);

$apiurl = get_config('block_vektra', 'apiurl');
$apikey = get_config('block_vektra', 'apikey');

if (empty($apiurl) || empty($apikey)) {
    http_response_code(500);
    echo json_encode(['error' => get_string('notconfigured', 'block_vektra')]);
    die();
}

// Load the specific block instance to read namespace/course_id config.
$instance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'vektra'], '*', MUST_EXIST);

if ($instance->parentcontextid != $context->id) {
    throw new \moodle_exception('invalidblockinstance', 'block_vektra');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$namespace = null;
$vektracourse = $course->shortname;

if (!empty($instance->configdata)) {
    $config = unserialize_object(base64_decode($instance->configdata));
    if (is_object($config)) {
        if (!empty($config->course_id)) {
            $vektracourse = $config->course_id;
        }
        $ns = $config->namespace ?? null;
        if (is_string($ns) && $ns !== '') {
            $namespace = $ns;
        }
    }
}

$client = new \block_vektra\vektra_client($apiurl, $apikey);
$result = $client->generate_token($USER->username, $vektracourse, $namespace);

if ($result === null) {
    http_response_code(502);
    echo json_encode(['error' => get_string('tokenerror', 'block_vektra')]);
    die();
}

echo json_encode(['token' => $result['token']]);
