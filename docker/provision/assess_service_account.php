<?php
// Dedicated service account for the Vektra assessment bot (REQ-079), idempotent.
// Derived from the OQ-025 spike (vektra-moodle-discuss spike/oq025/cli/provision.php).
//
//   php assess_service_account.php [--print-functions] [--caps=cap1,cap2]
//
// Env: ASSESS_WS_TOKEN  the permanent token of vektra_assess_svc (32 chars [0-9a-f]); when
//                       unset a random one is generated on first run and reused afterwards.
//      ASSESS_OUT       a file to write the JSON report to (tokens included); default stdout.
// Also provisions the negative accounts the REQ-079 setup-check tests need:
//   assess_bad_admin    site administrator, token on the vektra_assess service
//   assess_bad_grader   grade-write role, token on a service that lists a grade-write function
//   vektra_ingest_acct  stand-in for the ingestion account, token on a stub service
// None of them is created on a production site: this directory is dev-only, and the script refuses
// to run unless wwwroot is on loopback or VEKTRA_DEV=1 is set.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

// Dev-only guard: this script makes a site administrator and issues permanent tokens. Refuse to run
// unless the site is on loopback or the operator opted in explicitly with VEKTRA_DEV=1.
$vkhost = strtolower((string)parse_url($CFG->wwwroot, PHP_URL_HOST));
if (!in_array($vkhost, ['localhost', '127.0.0.1', '::1', '[::1]'], true) && getenv('VEKTRA_DEV') !== '1') {
    fwrite(STDERR, "REFUSING to run: wwwroot host '$vkhost' is not localhost/127.0.0.1/::1 and VEKTRA_DEV=1 is not set.\n"
        . "This script creates a site administrator and permanent web service tokens; it is for dev environments only.\n");
    exit(1);
}

// One source of truth for the service function list. READ functions only: REQ-079 as amended
// 2026-11-01 makes "no grade-write function in the list" the control. Printed for the docs.
const ASSESS_FUNCTIONS = [
    'core_webservice_get_site_info',
    'core_course_get_courses_by_field', 'core_course_get_contents', 'core_course_get_course_module',
    'core_enrol_get_enrolled_users', 'core_enrol_get_enrolled_users_with_capability',
    'core_enrol_get_users_courses',
    'core_group_get_course_user_groups', 'core_user_get_users_by_field',
    'mod_quiz_get_quizzes_by_courses', 'mod_quiz_get_user_attempts', 'mod_quiz_get_attempt_review',
    'mod_quiz_get_combined_review_options', 'mod_quiz_get_user_best_grade', 'mod_quiz_get_overrides',
    'mod_quiz_get_user_quiz_attempts', 'mod_quiz_get_quiz_feedback_for_grade',
    'mod_assign_get_assignments', 'mod_assign_get_submissions', 'mod_assign_get_submission_status',
    'mod_assign_get_grades', 'mod_assign_get_user_flags', 'mod_assign_get_participant',
    'core_grading_get_definitions', 'core_grading_get_gradingform_instances',
    'gradereport_user_get_grade_items',
];
// Capabilities of the read-only role (ablation-verified in the spike: each one breaks a read).
const ASSESS_CAPS = [
    'webservice/rest:use',
    'moodle/course:view', 'moodle/course:viewparticipants', 'moodle/course:viewhiddenactivities',
    'moodle/course:viewhiddencourses', 'moodle/site:accessallgroups', 'moodle/user:viewdetails',
    'moodle/grade:viewall', 'moodle/grade:viewhidden', 'moodle/grade:managegradingforms',
    'gradereport/user:view', 'moodle/grade:view',
    'mod/quiz:view', 'mod/quiz:viewreports', 'mod/quiz:reviewmyattempts', 'mod/quiz:viewoverrides',
    'mod/assign:view', 'mod/assign:viewgrades', 'moodle/role:review',
];

[$opts] = cli_get_params(['caps' => '', 'print-functions' => false]);
if ($opts['print-functions']) {
    echo implode("\n", ASSESS_FUNCTIONS), "\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$sys = context_system::instance();

set_config('enablewebservices', 1);
$protocols = array_filter(explode(',', (string)get_config('core', 'webserviceprotocols')));
if (!in_array('rest', $protocols)) { $protocols[] = 'rest'; set_config('webserviceprotocols', implode(',', $protocols)); }
// auth_webservice must be enabled: auth=nologin is REFUSED by the WS layer (wsaccessusernologin).
$auths = array_filter(explode(',', (string)get_config('core', 'auth')));
if (!in_array('webservice', $auths)) { $auths[] = 'webservice'; set_config('auth', implode(',', $auths)); \core\session\manager::gc(); }

function vk_user(string $username, string $last): stdClass {
    global $DB, $CFG;
    $u = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if (!$u) {
        $id = user_create_user((object)[
            'username' => $username, 'auth' => 'webservice', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Vektra', 'lastname' => $last,
            'email' => $username . '@example.invalid', 'password' => random_string(32),
        ], false, false);
        $u = $DB->get_record('user', ['id' => $id]);
    }
    if ($u->auth !== 'webservice') { $DB->set_field('user', 'auth', 'webservice', ['id' => $u->id]); $u->auth = 'webservice'; }
    return $u;
}
function vk_role(string $shortname, string $name, array $caps): int {
    global $DB;
    $sys = context_system::instance();
    $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
    if (!$roleid) {
        $roleid = create_role($name, $shortname, 'Vektra assess dev environment', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    }
    $DB->delete_records('role_capabilities', ['roleid' => $roleid, 'contextid' => $sys->id]);
    foreach ($caps as $c) {
        if (get_capability_info($c)) { assign_capability($c, CAP_ALLOW, $roleid, $sys->id, true); }
    }
    return (int)$roleid;
}
function vk_service(string $shortname, string $name, array $functions): stdClass {
    global $DB;
    $functions = array_values(array_filter($functions, fn($f) => $DB->record_exists('external_functions', ['name' => $f])));
    $service = $DB->get_record('external_services', ['shortname' => $shortname]);
    if (!$service) {
        $id = $DB->insert_record('external_services', (object)[
            'name' => $name, 'shortname' => $shortname, 'enabled' => 1, 'restrictedusers' => 1,
            'requiredcapability' => '', 'downloadfiles' => 1, 'uploadfiles' => 0, 'timecreated' => time(),
        ]);
        $service = $DB->get_record('external_services', ['id' => $id]);
    }
    $DB->delete_records('external_services_functions', ['externalserviceid' => $service->id]);
    foreach ($functions as $f) {
        $DB->insert_record('external_services_functions', (object)['externalserviceid' => $service->id, 'functionname' => $f]);
    }
    $service->functions = $functions;
    return $service;
}
// Permanent token upsert: oldest row kept, duplicates removed, rotation updates in place.
function vk_token(stdClass $service, stdClass $user, ?string $wanted): string {
    global $DB;
    $sys = context_system::instance();
    if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
        $DB->insert_record('external_services_users', (object)['externalserviceid' => $service->id, 'userid' => $user->id, 'timecreated' => time()]);
    }
    $rows = $DB->get_records('external_tokens', ['externalserviceid' => $service->id, 'userid' => $user->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT], 'timecreated ASC, id ASC');
    $row = $rows ? array_shift($rows) : false;
    foreach ($rows as $dup) { $DB->delete_records('external_tokens', ['id' => $dup->id]); }
    if ($row) {
        if ($wanted !== null && $row->token !== $wanted) {
            $row->token = $wanted;
            $DB->update_record('external_tokens', $row);
        }
        return $row->token;
    }
    $token = $wanted ?? md5(random_string(64));
    $DB->insert_record('external_tokens', (object)[
        'token' => $token, 'privatetoken' => random_string(64), 'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        'userid' => $user->id, 'externalserviceid' => $service->id, 'contextid' => $sys->id,
        'creatorid' => get_admin()->id, 'timecreated' => time(), 'validuntil' => 0,
    ]);
    return $token;
}

$wanted = getenv('ASSESS_WS_TOKEN') ?: null;
if ($wanted !== null && !preg_match('/^[0-9a-f]{32}$/', $wanted)) {
    fwrite(STDERR, "ASSESS_WS_TOKEN must be 32 lowercase hex characters\n");
    exit(1);
}
$caps = $opts['caps'] !== '' ? array_map('trim', explode(',', $opts['caps'])) : ASSESS_CAPS;

// --- the service account ---------------------------------------------------------------------
$user = vk_user('vektra_assess_svc', 'Assess service');
$roleid = vk_role('vektra_assess_reader', 'Vektra assess reader', $caps);
if (!user_has_role_assignment($user->id, $roleid, $sys->id)) { role_assign($roleid, $user->id, $sys->id); }
$service = vk_service('vektra_assess', 'Vektra assess', ASSESS_FUNCTIONS);
$token = vk_token($service, $user, $wanted);

// --- negative accounts (REQ-079 setup-check tests) ----------------------------------------------
$admin = vk_user('assess_bad_admin', 'Bad admin (site administrator)');
$siteadmins = array_filter(explode(',', (string)$CFG->siteadmins));
if (!in_array($admin->id, $siteadmins)) { $siteadmins[] = $admin->id; set_config('siteadmins', implode(',', $siteadmins)); }
$admintoken = vk_token($service, $admin, null);

$grader = vk_user('assess_bad_grader', 'Bad grader (grade-write)');
$graderrole = vk_role('vektra_assess_badgrader', 'Vektra assess bad grader',
    array_merge($caps, ['mod/assign:grade', 'moodle/grade:edit']));
if (!user_has_role_assignment($grader->id, $graderrole, $sys->id)) { role_assign($graderrole, $grader->id, $sys->id); }
// A service that lists a grade-write function: it is never CALLED, only listed.
$badservice = vk_service('vektra_assess_badgrader', 'Vektra assess bad grader (test)',
    array_merge(ASSESS_FUNCTIONS, ['mod_assign_save_grade']));
$gradertoken = vk_token($badservice, $grader, null);

$ingest = vk_user('vektra_ingest_acct', 'Ingestion account stand-in');
$ingestrole = vk_role('vektra_assess_ingeststub', 'Vektra ingestion stand-in', ['webservice/rest:use']);
if (!user_has_role_assignment($ingest->id, $ingestrole, $sys->id)) { role_assign($ingestrole, $ingest->id, $sys->id); }
$stub = vk_service('vektra_assess_ingeststub', 'Ingestion stand-in (test)', ['core_webservice_get_site_info']);
$ingesttoken = vk_token($stub, $ingest, null);

accesslib_clear_all_caches(true);
$report = json_encode([
    'release' => $CFG->release,
    'service_account' => ['username' => $user->username, 'userid' => (int)$user->id, 'auth' => $user->auth, 'token' => $token],
    'functions' => $service->functions,
    'caps' => $caps,
    'negative' => [
        'assess_bad_admin' => ['userid' => (int)$admin->id, 'token' => $admintoken],
        'assess_bad_grader' => ['userid' => (int)$grader->id, 'token' => $gradertoken, 'grade_write_function' => 'mod_assign_save_grade'],
        'vektra_ingest_acct' => ['userid' => (int)$ingest->id, 'token' => $ingesttoken],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$out = getenv('ASSESS_OUT');
if ($out) { file_put_contents($out, $report); chmod($out, 0600); echo "report written to $out\n"; } else { echo $report; }
