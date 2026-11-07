<?php
// Fixture seeder + self-check for the Vektra assessment bot (plan assess-moodle-ws, group A).
//
// DEV ENVIRONMENTS ONLY. Wraps Moodle's own data generators (testing_util::get_data_generator()) to
// seed ONE course from the declarative catalogue fixtures.json, on Moodle 4.3 and 5.2, writes
// /opt/vektra/fixtures/out/manifest-<tag>.json (ids, EXPECTED values, canary strings) and then
// verifies the seed straight against the Moodle DB.
//
//   docker exec -u www-data vektra-moodle43 php /opt/vektra/fixtures/seed_assess_fixtures.php
//   ... --check    only run the self-check (against the manifest + DB), seed nothing
//
// Prerequisite (once per container): docker exec -u root <c> bash /opt/vektra/fixtures/prepare_dev_deps.sh
//
// Exit codes: 0 seeded/already seeded and every check matches; 1 any self-check mismatch or error;
// 2 usage / state problem (e.g. course exists but manifest missing: reset the environment).
//
// Expected values are computed from fixtures.json, NEVER read back from what Moodle created. A
// fixture that cannot be seeded on a version is recorded as "seeded": false with a reason, and no
// check is registered for it (it is listed loudly at the end).

define('CLI_SCRIPT', true);
$configpath = getenv('MOODLE_CONFIG') ?: '/var/www/html/config.php';
require($configpath);
// Defined AFTER bootstrap so setup.php is not in Behat mode; it only unlocks quiz_attempt::get_question_usage()
// which the quiz generator needs. Never do this in production code.
define('BEHAT_TEST', true);
$CFG->noemailever = true;
// 4.3: lib/testing/classes/util.php; 5.x: autoloaded core\test\testing_util (class alias testing_util).
if (file_exists($CFG->libdir . '/testing/classes/util.php')) { require_once($CFG->libdir . '/testing/classes/util.php'); }
if (file_exists($CFG->libdir . '/testing/generator/lib.php')) { require_once($CFG->libdir . '/testing/generator/lib.php'); }
// Composer dev deps (PHPUnit) are needed by the question generator helpers; 5.x keeps vendor/ above public/.
$autoloaded = false;
foreach ([$CFG->dirroot . '/vendor/autoload.php', dirname($CFG->dirroot) . '/vendor/autoload.php'] as $al) {
    if (file_exists($al)) { require_once($al); $autoloaded = true; break; }
}
if (!$autoloaded) {
    fwrite(STDERR, "composer dev deps missing: run prepare_dev_deps.sh as root in this container first\n");
    exit(2);
}
set_exception_handler(function ($e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' | ' . ($e->debuginfo ?? '') . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/enrollib.php');

$branch = (int)$CFG->branch;                               // 403, 502
$tag = 'm' . intdiv($branch, 100) . ($branch % 100);       // m43, m52
$outdir = __DIR__ . '/out';
$manifestpath = "$outdir/manifest-$tag.json";
$checkonly = in_array('--check', $argv, true);

// ---------------------------------------------------------------------------------------------
// Catalogue loading: resolve {tag} and @now+N tokens.
// ---------------------------------------------------------------------------------------------
$now = time();
$resolve = function ($v) use (&$resolve, $tag, $now) {
    if (is_array($v)) { return array_map($resolve, $v); }
    if (!is_string($v)) { return $v; }
    if (preg_match('/^@now([+-])(\d+)([dhm]?)$/', $v, $mm)) {
        $mult = ['' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$mm[3]];
        return $now + ($mm[1] === '-' ? -1 : 1) * (int)$mm[2] * $mult;
    }
    return str_replace('{tag}', $tag, $v);
};
$catalogueraw = file_get_contents(__DIR__ . '/fixtures.json');
$catalogue = json_decode($catalogueraw, true, 512, JSON_THROW_ON_ERROR);
// The manifest records which catalogue seeded the environment: a different one needs a reset.
$cataloguesha = hash('sha256', $catalogueraw);

// ---------------------------------------------------------------------------------------------
// Self-check runner (kinds: sql scalar, capability, module visibility). Used right after seeding
// and by --check / idempotent re-runs. Returns the number of mismatches.
// ---------------------------------------------------------------------------------------------
$runchecks = function (array $checks): int {
    global $DB;
    $fail = 0;
    $n = 0;
    foreach ($checks as $c) {
        $n++;
        $kind = $c['kind'] ?? 'sql';
        try {
            if ($kind === 'sql') {
                $rows = $DB->get_records_sql($c['sql'], $c['params'] ?? [], 0, 1);
                $row = $rows ? (array)reset($rows) : [];
                $actual = $row ? reset($row) : null;
            } else if ($kind === 'cap') {
                $ctx = context_course::instance($c['courseid']);
                $actual = has_capability($c['cap'], $ctx, $c['userid']) ? 1 : 0;
            } else if ($kind === 'visible') {
                $cm = get_fast_modinfo($c['courseid'], $c['userid'])->get_cm($c['cmid']);
                $actual = $cm->uservisible ? 1 : 0;
            } else {
                throw new coding_exception("unknown check kind $kind");
            }
        } catch (Throwable $e) {
            $actual = 'ERROR ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '');
        }
        $exp = $c['expected'];
        $ok = (is_numeric($exp) && is_numeric($actual)) ? abs((float)$exp - (float)$actual) < 0.0001 : ((string)$exp === (string)$actual);
        if (!$ok) {
            $fail++;
            fwrite(STDERR, "MISMATCH [{$c['label']}] expected=" . var_export($exp, true) . " actual=" . var_export($actual, true) . "\n");
        }
    }
    fwrite(STDERR, "self-check: $n checks, $fail mismatches\n");
    return $fail;
};
$finish = function (array $checks, array $skipped) use ($runchecks): void {
    foreach ($skipped as $s) { fwrite(STDERR, "NOT SEEDED: {$s['fixture']}: {$s['reason']}\n"); }
    exit($runchecks($checks) === 0 ? 0 : 1);
};

// ---------------------------------------------------------------------------------------------
// Idempotency: the course shortname is the marker. Already seeded => seed nothing, just verify.
// ---------------------------------------------------------------------------------------------
$exists = $DB->record_exists('course', ['shortname' => $catalogue['course']['shortname']]);
if ($checkonly || $exists) {
    if (!$exists) { fwrite(STDERR, "course not seeded yet\n"); exit(2); }
    if (!file_exists($manifestpath)) {
        fwrite(STDERR, "course exists but $manifestpath is missing: reset the environment (make moodleNN-reset) and re-seed\n");
        exit(2);
    }
    $mf = json_decode(file_get_contents($manifestpath), true, 512, JSON_THROW_ON_ERROR);
    // A manifest from another catalogue (e.g. seeded before the burst fixture existed) would self-check only
    // what it seeded and report success for fixtures that are not there.
    if (($mf['catalogue_sha256'] ?? null) !== $cataloguesha) {
        fwrite(STDERR, "already seeded ($tag) from a different fixtures.json: reset the environment (make moodleNN-reset) and re-seed\n");
        exit(2);
    }
    fwrite(STDERR, "already seeded ($tag): seeding nothing, running self-check from the manifest\n");
    get_log_manager()->dispose();
    $finish($mf['checks'], $mf['seeded_false']);
}

// ===============================================================================================
// SEED
// ===============================================================================================
$catalogue = $resolve($catalogue);
\core\session\manager::set_user(get_admin());
$gen = testing_util::get_data_generator();
$qzgen = $gen->get_plugin_generator('mod_quiz');
$asgen = $gen->get_plugin_generator('mod_assign');
$qgen = $gen->get_plugin_generator('core_question');

$checks = [];       // self-check list, persisted in the manifest
$skipped = [];      // [{fixture, reason}] seeded:false entries
$canaries = [];     // token => where it lives
$m = ['release' => $CFG->release, 'version' => (string)$CFG->version, 'tag' => $tag, 'seeded_at' => $now,
    'catalogue_sha256' => $cataloguesha];
$m['ws_get_overrides_available'] = $DB->record_exists('external_functions', ['name' => 'mod_quiz_get_overrides']);

$addcheck = function (string $label, string $sql, array $params, $expected) use (&$checks) {
    $checks[] = ['kind' => 'sql', 'label' => $label, 'sql' => $sql, 'params' => $params, 'expected' => $expected];
};
$canary = function (string $kind, string $sub, string $table, string $field, array $where = []) use (&$canaries, $tag, $DB, &$checks) {
    $tok = "CANARY-$kind-$sub-$tag";
    if (isset($canaries[$tok])) { throw new coding_exception("canary not unique: $tok"); }
    $canaries[$tok] = ['table' => $table, 'field' => $field];
    return $tok;
};
// Every DB-resident canary is verified to exist in its table/field (see the end of the seed).

// --- course, users, groups, roles -------------------------------------------------------------
$course = $gen->create_course(['shortname' => $catalogue['course']['shortname'], 'fullname' => $catalogue['course']['fullname']]);
$cid = (int)$course->id;
$cctx = context_course::instance($cid);
$m['courseid'] = $cid;

$nogradeRole = $DB->get_field('role', 'id', ['shortname' => 'eaa_teacher_nograde']) ?:
    $gen->create_role(['shortname' => 'eaa_teacher_nograde', 'name' => 'Teacher without grade capability', 'archetype' => '']);
foreach (['moodle/course:view', 'moodle/course:viewparticipants', 'mod/assign:view', 'mod/quiz:view', 'moodle/user:viewdetails'] as $cap) {
    assign_capability($cap, CAP_ALLOW, $nogradeRole, context_system::instance()->id, true);
}
set_role_contextlevels($nogradeRole, [CONTEXT_COURSE]);

$u = [];
foreach ($catalogue['users'] as $ud) {
    $user = $gen->create_user(['username' => 'aas_' . $ud['key'], 'firstname' => $ud['firstname'], 'lastname' => $ud['lastname'],
        'email' => 'aas_' . $ud['key'] . '@example.invalid']);
    $gen->enrol_user($user->id, $cid, $ud['role']);
    $u[$ud['key']] = $user;
    $m['users'][$ud['key']] = ['id' => (int)$user->id, 'username' => $user->username, 'role' => $ud['role']];
    $addcheck("user {$ud['key']} enrolled as {$ud['role']}",
        "SELECT COUNT(1) FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid JOIN {context} c ON c.id = ra.contextid
          WHERE ra.userid = ? AND c.contextlevel = 50 AND c.instanceid = ? AND r.shortname = ?", [$user->id, $cid, $ud['role']], 1);
    $addcheck("user {$ud['key']} enrolment active",
        "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.userid = ? AND e.courseid = ? AND ue.status = 0", [$user->id, $cid], 1);
}
foreach ($catalogue['users'] as $ud) {
    $isgrader = in_array($ud['role'], ['editingteacher', 'teacher'], true);
    foreach (['mod/assign:grade', 'mod/quiz:grade'] as $cap) {
        $checks[] = ['kind' => 'cap', 'label' => "{$ud['key']} $cap", 'courseid' => $cid, 'userid' => (int)$u[$ud['key']]->id,
            'cap' => $cap, 'expected' => $isgrader ? 1 : 0];
    }
}
$m['graders'] = ['grader1', 'grader2'];
$m['teacher_without_grade_capability'] = 'tnocap';

$g = [];
foreach ($catalogue['groups'] as $gd) {
    $grp = $gen->create_group(['courseid' => $cid, 'name' => $gd['key']]);
    $g[$gd['key']] = $grp;
    $m['groups'][$gd['key']] = ['id' => (int)$grp->id, 'members' => $gd['members']];
    foreach ($gd['members'] as $mk) { $gen->create_group_member(['groupid' => $grp->id, 'userid' => $u[$mk]->id]); }
    $addcheck("group {$gd['key']} members", "SELECT COUNT(1) FROM {groups_members} WHERE groupid = ?", [$grp->id], count($gd['members']));
}
$scales = [];
foreach ($catalogue['scales'] as $sd) {
    $sc = $gen->create_scale(['name' => 'Scale ' . $sd['key'], 'scale' => $sd['scale'], 'courseid' => $cid]);
    $scales[$sd['key']] = $sc;
    $m['scales'][$sd['key']] = ['id' => (int)$sc->id, 'items' => explode(',', $sd['scale'])];
    $addcheck("scale {$sd['key']} items", "SELECT scale FROM {scale} WHERE id = ?", [$sc->id], $sd['scale']);
}

// --- question bank ---------------------------------------------------------------------------------
$cat = $qgen->create_question_category(['contextid' => $cctx->id]);
$q = [];
foreach ($catalogue['questions'] as $qk => $qd) {
    $ov = [
        'category' => $cat->id,
        'name' => "Q-$qk",
        'questiontext' => ['text' => "VISIBLE-QTEXT-$qk: question body", 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => $canary('GENFB', $qk, 'question', 'generalfeedback'), 'format' => FORMAT_HTML],
    ];
    if (!empty($qd['overrides'])) { $ov = array_merge($ov, $qd['overrides']); }
    $q[$qk] = $qgen->create_question($qd['qtype'], $qd['which'], $ov);
    $m['questions'][$qk] = ['id' => (int)$q[$qk]->id, 'qtype' => $qd['qtype'], 'defaultmark' => $qd['mark'],
        'discussable' => $qd['discussable'] ?? true, 'generalfeedback_canary' => "CANARY-GENFB-$qk-$tag"];
    $addcheck("question $qk qtype", "SELECT qtype FROM {question} WHERE id = ?", [$q[$qk]->id], $qd['qtype']);
    $addcheck("question $qk canary", "SELECT COUNT(1) FROM {question} WHERE id = ? AND generalfeedback LIKE ?", [$q[$qk]->id, '%CANARY-GENFB-' . $qk . '-' . $tag . '%'], 1);
}

// --- quizzes ----------------------------------------------------------------------------------------
$DURING = 0x10000; $IMM = 0x01000; $OPEN = 0x00100; $CLOSED = 0x00010; $ALL = $DURING | $IMM | $OPEN | $CLOSED;
$grademethods = ['highest' => QUIZ_GRADEHIGHEST, 'average' => QUIZ_GRADEAVERAGE, 'first' => QUIZ_ATTEMPTFIRST, 'last' => QUIZ_ATTEMPTLAST];
// profile => option => bitmask
$reviewprofiles = [
    'all'   => ['attempt' => $ALL, 'correctness' => $ALL, 'marks' => $ALL, 'specificfeedback' => $ALL, 'generalfeedback' => $ALL, 'rightanswer' => $ALL, 'overallfeedback' => $ALL],
    'none'  => ['attempt' => $ALL, 'correctness' => 0, 'marks' => $CLOSED, 'specificfeedback' => 0, 'generalfeedback' => 0, 'rightanswer' => 0, 'overallfeedback' => 0],
    'mixed' => ['attempt' => $ALL, 'correctness' => 0, 'marks' => $OPEN | $CLOSED, 'specificfeedback' => 0, 'generalfeedback' => $CLOSED, 'rightanswer' => $CLOSED, 'overallfeedback' => 0],
];
$autotypes = ['truefalse', 'multichoice', 'shortanswer', 'numerical'];

foreach ($catalogue['quizzes'] as $qd) {
    $k = $qd['key'];
    $s = $qd['settings'];
    $quiz = $gen->create_module('quiz', array_merge([
        'course' => $cid, 'name' => $qd['name'], 'grade' => 10, 'sumgrades' => 0, 'timelimit' => 0,
        'overduehandling' => 'autosubmit', 'graceperiod' => 0, 'questionsperpage' => 0,
        'preferredbehaviour' => 'deferredfeedback', 'attempts' => 0,
    ], array_diff_key($s, ['grademethod' => 1])));
    $qid = (int)$quiz->id;
    $upd = ['id' => $qid, 'grademethod' => $grademethods[$s['grademethod'] ?? 'highest']];
    // The generator rebuilds review* from form checkbox fields and ignores values passed in: set them in the DB.
    foreach ($reviewprofiles[$qd['review']] as $opt => $bits) { $upd['review' . $opt] = $bits; }
    $DB->update_record('quiz', (object)$upd);
    $afterclose = [];
    foreach ($reviewprofiles[$qd['review']] as $opt => $bits) { $afterclose[$opt] = (bool)($bits & $CLOSED); }

    // Items.
    $slots = [];
    $sumgrades = 0;
    foreach ($qd['items'] as $i => [$qk, $maxmark]) {
        quiz_add_quiz_question($q[$qk]->id, $quiz, 0, $maxmark);
        $slots[] = ['slot' => $i + 1, 'question' => $qk, 'qtype' => $catalogue['questions'][$qk]['qtype'], 'maxmark' => $maxmark];
        $sumgrades += $maxmark;
    }
    \mod_quiz\quiz_settings::create($qid)->get_grade_calculator()->recompute_quiz_sumgrades();
    $slotidx = function (string $qk) use ($qd) {
        foreach ($qd['items'] as $i => $it) { if ($it[0] === $qk) { return $i + 1; } }
        throw new coding_exception("question $qk not in quiz {$qd['key']}");
    };

    if (!empty($qd['overall_feedback'])) {
        $tok = $canary('OVERALLFB', $k, 'quiz_feedback', 'feedbacktext');
        $DB->insert_record('quiz_feedback', (object)['quizid' => $qid, 'feedbacktext' => $tok, 'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 0, 'maxgrade' => $s['grade'] + 1]);
        $addcheck("quiz $k overall feedback canary", "SELECT COUNT(1) FROM {quiz_feedback} WHERE quizid = ? AND feedbacktext = ?", [$qid, $tok], 1);
    }

    // Attempts. Session user must be the student for the generator to start the attempt.
    $attempts = [];
    $ungraded = [];
    $perUserScaled = [];   // user => [scaled grade per FINISHED attempt, in order]
    foreach ($qd['attempts'] as $n => $ad) {
        $st = $u[$ad['user']];
        $resp = [];
        $sum = 0.0;
        $slotstates = [];
        $essayanswered = false;
        $engineright = [];   // slots answered with the question engine's own correct response
        foreach ($ad['answers'] as $qk => $val) {
            $def = $catalogue['questions'][$qk];
            $slot = $slotidx($qk);
            $mark = null;
            foreach ($qd['items'] as $it) { if ($it[0] === $qk) { $mark = $it[1]; break; } }
            if (strpos($val, 'text:') === 0) {
                $resp[$slot] = substr($val, 5);
                $essayanswered = true;
            } else if (!isset($def['answers'])) {
                // Types without catalogue answers (match, multianswer, gapselect, ddwtos): only "right", taken from
                // the question engine, so the slot is graded with its full mark.
                if ($val !== 'right') {
                    throw new coding_exception("question $qk ({$def['qtype']}): only 'right' is supported without catalogue answers");
                }
                $engineright[] = $slot;
                $sum += $mark;
                $slotstates[$slot] = 'gradedright';
            } else {
                $resp[$slot] = $def['answers'][$val];
                if ($val === 'right') { $sum += $mark; }
                $slotstates[$slot] = $val === 'right' ? 'gradedright' : 'gradedwrong';
            }
        }
        \core\session\manager::set_user($st);
        $att = $qzgen->create_attempt($qid, $st->id);
        if ($engineright) {
            $quba = \mod_quiz\quiz_attempt::create($att->id)->get_question_usage();
            foreach ($engineright as $slot) { $quba->process_action($slot, $quba->get_correct_response($slot)); }
            question_engine::save_questions_usage_by_activity($quba);
        }
        $dofinish = $ad['state'] === 'finished';
        $qzgen->submit_responses($att->id, $resp, false, $dofinish);
        \core\session\manager::set_user(get_admin());
        $aid = (int)$att->id;
        if ($ad['state'] === 'overdue' || $ad['state'] === 'abandoned') {
            $ao = \mod_quiz\quiz_attempt::create($aid);
            if ($ad['state'] === 'overdue') { $ao->process_going_overdue($now, false); } else { $ao->process_abandon($now, false); }
        }
        // Slot states a correct reader must see for FINISHED attempts only (unanswered auto-graded items give up).
        $exp = null;
        if ($ad['state'] === 'finished') {
            $exp = [];
            foreach ($qd['items'] as $i => [$qk, $mk]) {
                $qt = $catalogue['questions'][$qk]['qtype'];
                $slot = $i + 1;
                if (isset($slotstates[$slot])) { $exp[$slot] = $slotstates[$slot]; }
                else if ($qt === 'essay') { $exp[$slot] = $essayanswered ? 'needsgrading' : 'gaveup'; }
                else if (in_array($qt, $autotypes, true)) { $exp[$slot] = 'gaveup'; }
            }
        }
        // Manual grade of an essay (grader mark).
        if (!empty($ad['manual'])) {
            foreach ($ad['manual'] as $qk => $mg) {
                $slot = $slotidx($qk);
                $tok = $canary('ESSAYCOMMENT', "$k-" . $ad['user'], 'question_attempt_step_data', 'value');
                $ao = \mod_quiz\quiz_attempt::create($aid);
                $tx = $DB->start_delegated_transaction();
                $ao->get_question_usage()->manual_grade($slot, $tok, $mg['mark'], FORMAT_HTML);
                question_engine::save_questions_usage_by_activity($ao->get_question_usage());
                $DB->set_field('quiz_attempts', 'sumgrades', $ao->get_question_usage()->get_total_mark(), ['id' => $aid]);
                $tx->allow_commit();
                \mod_quiz\quiz_settings::create($qid)->get_grade_calculator()->recompute_final_grade($st->id);
                $sum += $mg['mark'];
                $maxm = 0; foreach ($qd['items'] as $it) { if ($it[0] === $qk) { $maxm = $it[1]; } }
                $exp[$slot] = $mg['mark'] >= $maxm ? 'mangrright' : ($mg['mark'] > 0 ? 'mangrpartial' : 'mangrwrong');
            }
        }
        // An attempt with an answered, still ungraded essay has sumgrades NULL and contributes no quiz grade yet.
        $needsgrading = $ad['state'] === 'finished' && in_array('needsgrading', $exp ?? [], true);
        $expectedsum = ($ad['state'] === 'finished' && !$needsgrading) ? $sum : null;
        if ($needsgrading) { $ungraded[$ad['user']] = true; }
        if ($ad['state'] === 'finished' && !$needsgrading) {
            $perUserScaled[$ad['user']][] = $sumgrades > 0 ? $sum / $sumgrades * $s['grade'] : 0;
        }
        $attempts[] = ['id' => $aid, 'user' => $ad['user'], 'attempt_number' => count(array_filter($attempts, fn($a) => $a['user'] === $ad['user'])) + 1,
            'state' => $ad['state'], 'expected_sumgrades' => $expectedsum, 'expected_slot_states' => $exp];
    }

    // Close the quiz and backdate attempts of closed quizzes so timestart/timefinish precede timeclose.
    $DB->update_record('quiz', (object)['id' => $qid, 'timeopen' => $qd['open'], 'timeclose' => $qd['close']]);
    if ($qd['close'] < $now) {
        $DB->execute("UPDATE {quiz_attempts} SET timestart = timestart - 172800, timefinish = CASE WHEN timefinish > 0 THEN timefinish - 172800 ELSE 0 END,
                          timemodified = timemodified - 172800 WHERE quiz = ?", [$qid]);
        foreach ($attempts as $a) {
            if ($a['state'] === 'overdue') {
                $DB->execute("UPDATE {quiz_attempts} SET timecheckstate = timestart + ? WHERE id = ?", [(int)($s['timelimit'] ?? 0) + (int)($s['graceperiod'] ?? 0), $a['id']]);
            }
        }
    }
    if (!empty($qd['grade_item_hidden'])) {
        $gi = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $qid, 'courseid' => $cid, 'itemnumber' => 0]);
        $gi->set_hidden(1);
        $addcheck("quiz $k grade item hidden", "SELECT hidden FROM {grade_items} WHERE courseid = ? AND itemmodule = 'quiz' AND iteminstance = ? AND itemnumber = 0", [$cid, $qid], 1);
    }

    // Overrides (user + group), seeded on both versions; the WS function reading them exists on 5.2 only.
    $overrides = [];
    foreach ($qd['overrides'] ?? [] as $od) {
        $row = ['quiz' => $qid, 'timeclose' => $od['timeclose'], 'timelimit' => $od['timelimit']];
        if (isset($od['user'])) { $row['userid'] = $u[$od['user']]->id; } else { $row['groupid'] = $g[$od['group']]->id; }
        try {
            $qzgen->create_override($row);
            $overrides[] = ['user' => $od['user'] ?? null, 'group' => $od['group'] ?? null, 'timeclose' => $od['timeclose'], 'timelimit' => $od['timelimit']];
            $w = isset($od['user']) ? 'userid = ?' : 'groupid = ?';
            $wid = isset($od['user']) ? $u[$od['user']]->id : $g[$od['group']]->id;
            $addcheck("quiz $k override " . ($od['user'] ?? $od['group']) . ' timeclose', "SELECT timeclose FROM {quiz_overrides} WHERE quiz = ? AND $w", [$qid, $wid], $od['timeclose']);
            $addcheck("quiz $k override " . ($od['user'] ?? $od['group']) . ' timelimit', "SELECT timelimit FROM {quiz_overrides} WHERE quiz = ? AND $w", [$qid, $wid], $od['timelimit']);
        } catch (Throwable $e) {
            $skipped[] = ['fixture' => "quiz $k override", 'reason' => $e->getMessage()];
        }
    }
    $addcheck("quiz $k override count", "SELECT COUNT(1) FROM {quiz_overrides} WHERE quiz = ?", [$qid], count($overrides));
    // Effective close/limit a correct all-participant reader must give per user: user override > group override > quiz.
    $effective = [];
    if ($overrides) {
        foreach ($catalogue['users'] as $ud) {
            if ($ud['role'] !== 'student') { continue; }
            $eff = ['timeclose' => $qd['close'], 'timelimit' => $s['timelimit'] ?? 0, 'source' => 'quiz'];
            foreach ($overrides as $ov) {
                if ($ov['group'] && in_array($ud['key'], $m['groups'][$ov['group']]['members'], true)) {
                    $eff = ['timeclose' => $ov['timeclose'], 'timelimit' => $ov['timelimit'], 'source' => 'group:' . $ov['group']];
                }
            }
            foreach ($overrides as $ov) {
                if ($ov['user'] === $ud['key']) { $eff = ['timeclose' => $ov['timeclose'], 'timelimit' => $ov['timelimit'], 'source' => 'user']; }
            }
            $effective[$ud['key']] = $eff;
        }
    }

    // Structure + settings + attempts checks (from the catalogue, never from Moodle).
    $bytype = [];
    foreach ($slots as $sl) { $bytype[$sl['qtype']] = ($bytype[$sl['qtype']] ?? 0) + 1; }
    $addcheck("quiz $k slots", "SELECT COUNT(1) FROM {quiz_slots} WHERE quizid = ?", [$qid], count($slots));
    foreach ($bytype as $qt => $cnt) {
        $addcheck("quiz $k slots of type $qt",
            "SELECT COUNT(1) FROM {quiz_slots} s JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid JOIN {question} qq ON qq.id = qv.questionid
              WHERE s.quizid = ? AND qq.qtype = ?", [$qid, $qt], $cnt);
    }
    $addcheck("quiz $k sumgrades", "SELECT sumgrades FROM {quiz} WHERE id = ?", [$qid], $sumgrades);
    $addcheck("quiz $k timeclose", "SELECT timeclose FROM {quiz} WHERE id = ?", [$qid], $qd['close']);
    $addcheck("quiz $k grademethod", "SELECT grademethod FROM {quiz} WHERE id = ?", [$qid], $grademethods[$s['grademethod'] ?? 'highest']);
    $addcheck("quiz $k overduehandling", "SELECT overduehandling FROM {quiz} WHERE id = ?", [$qid], $s['overduehandling'] ?? 'autosubmit');
    $addcheck("quiz $k graceperiod", "SELECT graceperiod FROM {quiz} WHERE id = ?", [$qid], $s['graceperiod'] ?? 0);
    $addcheck("quiz $k timelimit", "SELECT timelimit FROM {quiz} WHERE id = ?", [$qid], $s['timelimit'] ?? 0);
    foreach ($reviewprofiles[$qd['review']] as $opt => $bits) {
        $addcheck("quiz $k review$opt bits", "SELECT review$opt FROM {quiz} WHERE id = ?", [$qid], $bits);
    }
    $count = [];
    foreach ($attempts as $a) { $count[$a['state']] = ($count[$a['state']] ?? 0) + 1; }
    foreach (['inprogress', 'overdue', 'finished', 'abandoned'] as $state) {
        $addcheck("quiz $k attempts $state", "SELECT COUNT(1) FROM {quiz_attempts} WHERE quiz = ? AND state = ?", [$qid, $state], $count[$state] ?? 0);
    }
    foreach ($attempts as $a) {
        $addcheck("quiz $k attempt {$a['user']}#{$a['attempt_number']} state", "SELECT state FROM {quiz_attempts} WHERE id = ?", [$a['id']], $a['state']);
        $addcheck("quiz $k attempt {$a['user']}#{$a['attempt_number']} owner", "SELECT userid FROM {quiz_attempts} WHERE id = ?", [$a['id']], $u[$a['user']]->id);
        if ($a['expected_sumgrades'] !== null) {
            $addcheck("quiz $k attempt {$a['user']}#{$a['attempt_number']} sumgrades", "SELECT sumgrades FROM {quiz_attempts} WHERE id = ?", [$a['id']], $a['expected_sumgrades']);
        }
        foreach ($a['expected_slot_states'] ?? [] as $slot => $stexp) {
            $addcheck("quiz $k attempt {$a['user']}#{$a['attempt_number']} slot $slot state",
                "SELECT qas.state FROM {question_attempts} qa JOIN {quiz_attempts} qza ON qza.uniqueid = qa.questionusageid
                   JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                  WHERE qza.id = ? AND qa.slot = ? ORDER BY qas.sequencenumber DESC", [$a['id'], $slot], $stexp);
        }
    }
    // Final grade per user per grading method, from the catalogue.
    $quizgrades = [];
    foreach ($perUserScaled as $ukey => $list) {
        $gm = $s['grademethod'] ?? 'highest';
        $val = $gm === 'highest' ? max($list) : ($gm === 'first' ? $list[0] : ($gm === 'last' ? end($list) : array_sum($list) / count($list)));
        $quizgrades[$ukey] = $val;
        $addcheck("quiz $k final grade $ukey ($gm)", "SELECT grade FROM {quiz_grades} WHERE quiz = ? AND userid = ?", [$qid, $u[$ukey]->id], $val);
    }
    foreach ($ungraded as $ukey => $x) {
        if (!isset($quizgrades[$ukey])) {
            $addcheck("quiz $k no final grade yet $ukey (needs grading)", "SELECT grade FROM {quiz_grades} WHERE quiz = ? AND userid = ?", [$qid, $u[$ukey]->id], null);
        }
    }
    $ties = [];
    if (($s['grademethod'] ?? '') === 'highest') {
        foreach ($perUserScaled as $ukey => $list) {
            $mx = max($list);
            $idx = array_keys(array_filter($list, fn($x) => abs($x - $mx) < 1e-9));
            if (count($idx) > 1) { $ties[$ukey] = array_map(fn($i) => $i + 1, $idx); }
        }
    }

    $m['quizzes'][$k] = [
        'id' => $qid, 'cmid' => (int)$quiz->cmid, 'name' => $qd['name'], 'seeded' => true,
        'expected' => ['timeopen' => $qd['open'], 'timeclose' => $qd['close'], 'timelimit' => $s['timelimit'] ?? 0,
            'overduehandling' => $s['overduehandling'] ?? 'autosubmit', 'graceperiod' => $s['graceperiod'] ?? 0,
            'grademethod' => $s['grademethod'] ?? 'highest', 'grade' => $s['grade'], 'sumgrades' => $sumgrades,
            'review_after_close' => $afterclose, 'grade_item_hidden' => !empty($qd['grade_item_hidden']),
            'final_grades' => $quizgrades, 'highest_ties_attempt_numbers' => $ties,
            'overrides' => $overrides, 'effective_by_user' => $effective],
        'slots' => $slots, 'attempts' => $attempts,
    ];
}

// --- assignments -------------------------------------------------------------------------------------
$tmpdir = $CFG->tempdir . '/eaassess_files';
check_dir_exists($tmpdir);
$relpath = function (string $abs) use ($CFG) { return str_repeat('../', substr_count(trim($CFG->dirroot, '/'), '/') + 1) . ltrim($abs, '/'); };
$files = eaa_build_sample_files($tmpdir, $tag);     // name => ['path', 'mimetype', 'canary']

$rubricgen = $gen->get_plugin_generator('gradingform_rubric');
$guidegen = $gen->get_plugin_generator('gradingform_guide');
$mimes = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt' => 'application/vnd.oasis.opendocument.text', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain'];

foreach ($catalogue['assigns'] as $ad) {
    $k = $ad['key'];
    $scale = isset($ad['scale']) ? $scales[$ad['scale']] : null;
    $as = $gen->create_module('assign', [
        'course' => $cid, 'name' => $ad['name'], 'grade' => $scale ? -(int)$scale->id : 100,
        'allowsubmissionsfromdate' => $now - 10 * 86400, 'duedate' => $ad['duedate'], 'cutoffdate' => $ad['cutoffdate'],
        'gradingduedate' => $now + 86400,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
        'assignsubmission_file_maxfiles' => $ad['maxfiles'] ?? 5, 'assignsubmission_file_maxsizebytes' => 1048576,
        'assignfeedback_comments_enabled' => 1, 'submissiondrafts' => 0,
        'teamsubmission' => $ad['teamsubmission'] ?? 0, 'markingworkflow' => $ad['markingworkflow'] ?? 0,
    ]);
    $aid = (int)$as->id;
    $actx = context_module::instance($as->cmid);
    $exp = ['duedate' => $ad['duedate'], 'cutoffdate' => $ad['cutoffdate'], 'teamsubmission' => $ad['teamsubmission'] ?? 0,
        'markingworkflow' => $ad['markingworkflow'] ?? 0, 'grading_method' => 'none', 'grade_type' => $scale ? 'scale' : 'point',
        'reader_by_user' => []];
    $addcheck("assign $k duedate", "SELECT duedate FROM {assign} WHERE id = ?", [$aid], $ad['duedate']);
    $addcheck("assign $k cutoffdate", "SELECT cutoffdate FROM {assign} WHERE id = ?", [$aid], $ad['cutoffdate']);
    $addcheck("assign $k teamsubmission", "SELECT teamsubmission FROM {assign} WHERE id = ?", [$aid], $exp['teamsubmission']);
    $addcheck("assign $k markingworkflow", "SELECT markingworkflow FROM {assign} WHERE id = ?", [$aid], $exp['markingworkflow']);
    $addcheck("assign $k grade type", "SELECT grade FROM {assign} WHERE id = ?", [$aid], $scale ? -(int)$scale->id : 100);

    // Advanced grading definitions.
    $controller = null;
    $ctrlkind = null;
    if (!empty($ad['rubric'])) {
        $rd = $ad['rubric'];
        $crit = [];
        foreach ($rd['criteria'] as $cn => $levels) {
            $lv = [];
            foreach ($levels as $ln => $score) { $lv[$canary('RUBRICLVL', "$k-$cn-$ln", 'gradingform_rubric_levels', 'definition')] = $score; }
            $crit[$canary('RUBRICCRIT', "$k-$cn", 'gradingform_rubric_criteria', 'description')] = $lv;
        }
        $desc = $canary('RUBRICDESC', $k, 'grading_definitions', 'description');
        $controller = $rubricgen->create_instance($actx, 'mod_assign', 'submissions', $rd['name'], $desc, $crit);
        $def = $controller->get_definition();
        $opts = json_decode($def->options ?? '{}', true) ?: [];
        $opts['showdescriptionstudent'] = $rd['showdescriptionstudent'];
        $opts['showdescriptionteacher'] = 1;
        $DB->set_field('grading_definitions', 'options', json_encode($opts), ['id' => $def->id]);
        $ctrlkind = 'rubric';
        $exp['grading_method'] = 'rubric';
        $exp['rubric'] = ['definitionid' => (int)$def->id, 'showdescriptionstudent' => (int)$rd['showdescriptionstudent'], 'criteria' => count($rd['criteria']),
            'levels_per_criterion' => 3, 'canary_criterion_descriptions' => array_keys($crit)];
        $addcheck("assign $k rubric active method", "SELECT activemethod FROM {grading_areas} WHERE contextid = ? AND component = 'mod_assign' AND areaname = 'submissions'", [$actx->id], 'rubric');
        $addcheck("assign $k rubric criteria", "SELECT COUNT(1) FROM {gradingform_rubric_criteria} WHERE definitionid = ?", [$def->id], count($rd['criteria']));
        $addcheck("assign $k rubric levels", "SELECT COUNT(1) FROM {gradingform_rubric_levels} l JOIN {gradingform_rubric_criteria} c ON c.id = l.criterionid WHERE c.definitionid = ?", [$def->id], 3 * count($rd['criteria']));
        $addcheck("assign $k rubric showdescriptionstudent", "SELECT options FROM {grading_definitions} WHERE id = ?", [$def->id], json_encode($opts));
    }
    if (!empty($ad['guide'])) {
        $gd = $ad['guide'];
        $crit = [];
        foreach ($gd['criteria'] as $cn => $cr) {
            $crit[$cn] = ['description' => $cr['description'], 'maxscore' => $cr['maxscore'],
                'descriptionmarkers' => $canary('GUIDEMARKERS', "$k-$cn", 'gradingform_guide_criteria', 'descriptionmarkers')];
        }
        $controller = $guidegen->create_instance($actx, 'mod_assign', 'submissions', $gd['name'], 'Guide description', $crit);
        $def = $controller->get_definition();
        $exp['grading_method'] = 'guide';
        $exp['guide'] = ['definitionid' => (int)$def->id, 'criteria' => count($crit), 'max_total' => array_sum(array_column($gd['criteria'], 'maxscore'))];
        $addcheck("assign $k guide active method", "SELECT activemethod FROM {grading_areas} WHERE contextid = ? AND component = 'mod_assign' AND areaname = 'submissions'", [$actx->id], 'guide');
        $addcheck("assign $k guide criteria", "SELECT COUNT(1) FROM {gradingform_guide_criteria} WHERE definitionid = ?", [$def->id], count($crit));
    }
    if ($exp['grading_method'] === 'none') {
        $addcheck("assign $k no advanced grading", "SELECT COUNT(1) FROM {grading_areas} WHERE contextid = ? AND component = 'mod_assign' AND activemethod IS NOT NULL AND activemethod <> ''", [$actx->id], 0);
    }

    // Submissions.
    $subs = [];
    foreach ($ad['submissions'] as $sd) {
        $data = ['userid' => $u[$sd['user']]->id, 'cmid' => $as->cmid, 'status' => 'submitted'];
        $subexp = ['user' => $sd['user'], 'status' => 'submitted', 'onlinetext' => null, 'files' => []];
        if (!empty($sd['onlinetext'])) {
            $tx = "VISIBLE-ONLINETEXT-$k-{$sd['user']}-$tag";
            $data['onlinetext'] = $tx;
            $subexp['onlinetext'] = $tx;
        }
        $paths = [];
        foreach ($sd['files'] ?? [] as $fk) {
            if ($fk === 'many11') {
                for ($i = 1; $i <= 11; $i++) {
                    $nm = sprintf('multi_%02d.txt', $i);
                    $p = "$tmpdir/$nm";
                    file_put_contents($p, "VISIBLE-FILE-$nm-$tag\n");
                    $paths[] = $relpath($p);
                    $subexp['files'][] = ['filename' => $nm, 'mimetype' => $mimes['txt']];
                }
            } else {
                $paths[] = $relpath($files[$fk]['path']);
                $subexp['files'][] = ['filename' => basename($files[$fk]['path']), 'mimetype' => $files[$fk]['mimetype'], 'content_canary' => $files[$fk]['canary']];
            }
        }
        if ($paths) { $data['file'] = implode(',', $paths); }
        $asgen->create_submission($data);
        $subs[$sd['user']] = $subexp;
        $uid = $u[$sd['user']]->id;
        $teamgroup = !empty($ad['teamsubmission']) ? $g['G1']->id : 0;
        // Team submissions are stored against the group (userid 0), plain ones against the user.
        $subwhere = $teamgroup ? 's.assignment = ? AND s.groupid = ? AND s.userid = 0 AND s.latest = 1' : 's.assignment = ? AND s.userid = ? AND s.latest = 1';
        $subparam = $teamgroup ? [$aid, $teamgroup] : [$aid, $uid];
        $addcheck("assign $k submission {$sd['user']} status", "SELECT s.status FROM {assign_submission} s WHERE $subwhere", $subparam, 'submitted');
        if ($subexp['onlinetext'] !== null) {
            $addcheck("assign $k submission {$sd['user']} onlinetext",
                "SELECT ot.onlinetext FROM {assignsubmission_onlinetext} ot JOIN {assign_submission} s ON s.id = ot.submission WHERE $subwhere", $subparam, $subexp['onlinetext']);
        }
        $addcheck("assign $k submission {$sd['user']} file count",
            "SELECT COUNT(1) FROM {files} f JOIN {assign_submission} s ON s.id = f.itemid WHERE $subwhere AND f.component = 'assignsubmission_file'
               AND f.filearea = 'submission_files' AND f.filename <> '.'", $subparam, count($subexp['files']));
        foreach ($subexp['files'] as $fe) {
            $addcheck("assign $k submission {$sd['user']} file {$fe['filename']} mimetype",
                "SELECT f.mimetype FROM {files} f JOIN {assign_submission} s ON s.id = f.itemid WHERE $subwhere AND f.component = 'assignsubmission_file'
                   AND f.filearea = 'submission_files' AND f.filename = ?", array_merge($subparam, [$fe['filename']]), $fe['mimetype']);
            $addcheck("assign $k submission {$sd['user']} file {$fe['filename']} non-empty",
                "SELECT CASE WHEN f.filesize > 0 THEN 1 ELSE 0 END FROM {files} f JOIN {assign_submission} s ON s.id = f.itemid WHERE $subwhere AND f.component = 'assignsubmission_file'
                   AND f.filearea = 'submission_files' AND f.filename = ?", array_merge($subparam, [$fe['filename']]), 1);
        }
    }
    $nsub = count($ad['submissions']);
    // Team submissions are stored once for the group (userid 0) and once per group member.
    $nrows = !empty($ad['teamsubmission']) ? 1 + count($m['groups']['G1']['members']) : $nsub;
    $addcheck("assign $k submissions (latest rows)", "SELECT COUNT(1) FROM {assign_submission} WHERE assignment = ? AND latest = 1 AND status = 'submitted'", [$aid], $nrows);

    // Extensions + overrides.
    foreach ($ad['extensions'] ?? [] as $ed) {
        $asgen->create_extension(['userid' => $u[$ed['user']]->id, 'cmid' => $as->cmid, 'extensionduedate' => $ed['extensionduedate']]);
        $exp['reader_by_user'][$ed['user']]['extensionduedate'] = $ed['extensionduedate'];
        $addcheck("assign $k extension {$ed['user']}", "SELECT extensionduedate FROM {assign_user_flags} WHERE assignment = ? AND userid = ?", [$aid, $u[$ed['user']]->id], $ed['extensionduedate']);
    }
    $addcheck("assign $k extensions count", "SELECT COUNT(1) FROM {assign_user_flags} WHERE assignment = ? AND extensionduedate > 0", [$aid], count($ad['extensions'] ?? []));
    foreach ($ad['overrides'] ?? [] as $od) {
        $asgen->create_override(['assignid' => $aid, 'userid' => $u[$od['user']]->id, 'duedate' => $od['duedate'], 'cutoffdate' => $od['cutoffdate']]);
        $exp['reader_by_user'][$od['user']]['duedate'] = $od['duedate'];
        $exp['reader_by_user'][$od['user']]['cutoffdate'] = $od['cutoffdate'];
        $addcheck("assign $k override {$od['user']} cutoffdate", "SELECT cutoffdate FROM {assign_overrides} WHERE assignid = ? AND userid = ?", [$aid, $u[$od['user']]->id], $od['cutoffdate']);
        $addcheck("assign $k override {$od['user']} duedate", "SELECT duedate FROM {assign_overrides} WHERE assignid = ? AND userid = ?", [$aid, $u[$od['user']]->id], $od['duedate']);
    }
    $addcheck("assign $k overrides count", "SELECT COUNT(1) FROM {assign_overrides} WHERE assignid = ?", [$aid], count($ad['overrides'] ?? []));

    // Grades (direct, scale, workflow) and rubric fillings.
    [$gcourse, $gcm] = get_course_and_cm_from_cmid($as->cmid, 'assign');
    $assign = new assign($actx, $gcm, $gcourse);
    $grades = [];
    $mkdata = function () { $d = new stdClass(); $d->attemptnumber = -1; $d->sendstudentnotifications = 0; return $d; };
    foreach ($ad['grades'] ?? [] as $gd) {
        $d = $mkdata();
        $d->grade = $gd['grade'];
        $wf = $gd['workflowstate'] ?? null;
        $hiddenitem = !empty($ad['grade_item_hidden']) || $wf === 'inmarking';
        $fbkind = $hiddenitem ? 'UNRELEASEDFB' : 'FB';
        $fb = $hiddenitem ? $canary($fbkind, "$k-{$gd['user']}", 'assignfeedback_comments', 'commenttext') : "VISIBLE-FEEDBACK-$k-{$gd['user']}-$tag";
        $d->assignfeedbackcomments_editor = ['text' => $fb, 'format' => FORMAT_HTML];
        if ($wf) { $d->workflowstate = $wf; }
        $assign->save_grade($u[$gd['user']]->id, $d);
        $gstatus = $wf ?: 'graded';
        $grades[] = ['user' => $gd['user'], 'grade' => $gd['grade'], 'gradingstatus' => $gstatus, 'feedback' => $fb, 'feedback_is_canary' => $hiddenitem];
        $addcheck("assign $k grade {$gd['user']}", "SELECT grade FROM {assign_grades} WHERE assignment = ? AND userid = ? AND attemptnumber = 0", [$aid, $u[$gd['user']]->id], $gd['grade']);
        $addcheck("assign $k feedback {$gd['user']}", "SELECT fc.commenttext FROM {assignfeedback_comments} fc JOIN {assign_grades} ag ON ag.id = fc.grade WHERE ag.assignment = ? AND ag.userid = ?", [$aid, $u[$gd['user']]->id], $fb);
        if ($wf) {
            $addcheck("assign $k workflow {$gd['user']}", "SELECT workflowstate FROM {assign_user_flags} WHERE assignment = ? AND userid = ?", [$aid, $u[$gd['user']]->id], $wf);
        }
    }
    if ($ctrlkind === 'rubric' && !empty($ad['rubric']['grade'])) {
        $rg = $ad['rubric']['grade'];
        $vals = [];
        $total = 0; $max = 0;
        foreach ($ad['rubric']['criteria'] as $cn => $levels) {
            $vals["CANARY-RUBRICCRIT-$k-$cn-$tag"] = ['score' => $rg['levels'][$cn], 'remark' => "VISIBLE-REMARK-$k-$cn-$tag"];
            $total += $rg['levels'][$cn]; $max += max($levels);
        }
        $d = $mkdata();
        $grade = $assign->get_user_grade($u[$rg['user']]->id, true);
        $d->advancedgrading = $rubricgen->get_submitted_form_data($controller, (int)$grade->id, $vals);
        $d->assignfeedbackcomments_editor = ['text' => "VISIBLE-FEEDBACK-$k-{$rg['user']}-$tag", 'format' => FORMAT_HTML];
        $assign->save_grade($u[$rg['user']]->id, $d);
        $expgrade = $total / $max * 100;
        $grades[] = ['user' => $rg['user'], 'grade' => $expgrade, 'gradingstatus' => 'graded', 'rubric_total' => $total, 'rubric_max' => $max,
            'rubric_levels' => $rg['levels']];
        $addcheck("assign $k rubric grade {$rg['user']}", "SELECT grade FROM {assign_grades} WHERE assignment = ? AND userid = ? AND attemptnumber = 0", [$aid, $u[$rg['user']]->id], $expgrade);
        $addcheck("assign $k grading instances", "SELECT COUNT(1) FROM {grading_instances} WHERE definitionid = ? AND status = 1", [$exp['rubric']['definitionid']], 1);
        $addcheck("assign $k rubric fillings", "SELECT COUNT(1) FROM {gradingform_rubric_fillings} f JOIN {grading_instances} i ON i.id = f.instanceid WHERE i.definitionid = ?", [$exp['rubric']['definitionid']], count($ad['rubric']['criteria']));
    }
    if ($ctrlkind === null || $ctrlkind === 'guide') {
        $addcheck("assign $k grading instances (none expected)", "SELECT COUNT(1) FROM {grading_instances} i JOIN {grading_definitions} d ON d.id = i.definitionid
            JOIN {grading_areas} a ON a.id = d.areaid WHERE a.contextid = ?", [$actx->id], 0);
    }
    $addcheck("assign $k grades count", "SELECT COUNT(1) FROM {assign_grades} WHERE assignment = ? AND grade >= 0", [$aid], count($grades));
    if ($scale) {
        $exp['scale'] = ['id' => (int)$scale->id, 'items' => explode(',', $scale->scale)];
        $grades[array_key_first($grades)]['scale_label'] = explode(',', $scale->scale)[$grades[array_key_first($grades)]['grade'] - 1];
    }

    // Grade item visibility (hidden / hidden until).
    if (!empty($ad['grade_item_hidden'])) {
        $gi = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $aid, 'courseid' => $cid, 'itemnumber' => 0]);
        $gi->set_hidden($ad['grade_item_hidden']);
        $exp['grade_item_hidden'] = $ad['grade_item_hidden'];
        $exp['gradeishidden'] = true;
        $addcheck("assign $k grade item hidden", "SELECT hidden FROM {grade_items} WHERE courseid = ? AND itemmodule = 'assign' AND iteminstance = ? AND itemnumber = 0", [$cid, $aid], $ad['grade_item_hidden']);
    } else {
        $exp['gradeishidden'] = false;
    }

    // Module visibility restriction: hide for one student ("not any of": profile email equals the student's, hidden entirely; 'username' is not a supported profile field).
    if (!empty($ad['hidden_for'])) {
        $av = json_encode(['op' => '!|', 'showc' => [false], 'c' => [['type' => 'profile', 'sf' => 'email', 'op' => 'isequalto', 'v' => 'aas_' . $ad['hidden_for'] . '@example.invalid']]]);
        $DB->set_field('course_modules', 'availability', $av, ['id' => $as->cmid]);
        rebuild_course_cache($cid, true);
        $exp['hidden_for'] = $ad['hidden_for'];
        $exp['visible_for'] = array_values(array_diff(array_column(array_filter($catalogue['users'], fn($x) => $x['role'] === 'student'), 'key'), [$ad['hidden_for']]));
        foreach ($catalogue['users'] as $ud) {
            if ($ud['role'] !== 'student') { continue; }
            $checks[] = ['kind' => 'visible', 'label' => "assign $k visible for {$ud['key']}", 'courseid' => $cid, 'cmid' => (int)$as->cmid,
                'userid' => (int)$u[$ud['key']]->id, 'expected' => $ud['key'] === $ad['hidden_for'] ? 0 : 1];
        }
    }

    $m['assigns'][$k] = ['id' => $aid, 'cmid' => (int)$as->cmid, 'name' => $ad['name'], 'seeded' => true, 'expected' => $exp,
        'submissions' => $subs, 'grades' => $grades];
}

// --- burst: one assignment, N students, each with a graded and released N-file submission ------------------
// A course of its own, so the main course's fixtures and their recorded WS responses stay unchanged.
if (!empty($catalogue['burst'])) {
    $bd = $catalogue['burst'];
    $bcourse = $gen->create_course(['shortname' => $bd['course']['shortname'], 'fullname' => $bd['course']['fullname']]);
    $bas = $gen->create_module('assign', [
        'course' => $bcourse->id, 'name' => $bd['name'], 'grade' => 100,
        'allowsubmissionsfromdate' => $now - 10 * 86400, 'duedate' => $bd['duedate'], 'cutoffdate' => $bd['cutoffdate'],
        'gradingduedate' => $now + 86400,
        'assignsubmission_onlinetext_enabled' => 0, 'assignsubmission_file_enabled' => 1,
        'assignsubmission_file_maxfiles' => $bd['files_per_submission'], 'assignsubmission_file_maxsizebytes' => 1048576,
        'assignfeedback_comments_enabled' => 1, 'submissiondrafts' => 0, 'teamsubmission' => 0, 'markingworkflow' => 0,
    ]);
    $bactx = context_module::instance($bas->cmid);
    [$bgcourse, $bgcm] = get_course_and_cm_from_cmid($bas->cmid, 'assign');
    $bassign = new assign($bactx, $bgcm, $bgcourse);
    // Each submission: the four readable samples, the scanned PDF, and text files up to N, all synthetic.
    $samples = ['pdf_text', 'docx', 'odt', 'pptx', 'pdf_scan'];
    $nfiles = (int)$bd['files_per_submission'];
    $bstudents = [];
    for ($i = 1; $i <= (int)$bd['students']; $i++) {
        $key = sprintf('bst%02d', $i);
        $bu = $gen->create_user(['username' => 'aas_' . $key, 'firstname' => 'Burst', 'lastname' => sprintf('Student %02d', $i),
            'email' => 'aas_' . $key . '@example.invalid']);
        $gen->enrol_user($bu->id, $bcourse->id, 'student');
        $paths = [];
        foreach (array_slice($samples, 0, $nfiles) as $fk) { $paths[] = $relpath($files[$fk]['path']); }
        for ($f = count($paths) + 1; $f <= $nfiles; $f++) {
            $p = sprintf('%s/burst_%s_%02d.txt', $tmpdir, $key, $f);
            file_put_contents($p, "VISIBLE-BURST-$key-$f-$tag\n");
            $paths[] = $relpath($p);
        }
        $asgen->create_submission(['userid' => $bu->id, 'cmid' => $bas->cmid, 'status' => 'submitted', 'file' => implode(',', $paths)]);
        $d = new stdClass();
        $d->attemptnumber = -1;
        $d->sendstudentnotifications = 0;
        $d->grade = $bd['grade'];
        $d->assignfeedbackcomments_editor = ['text' => "VISIBLE-FEEDBACK-burst-$key-$tag", 'format' => FORMAT_HTML];
        $bassign->save_grade($bu->id, $d);
        $bstudents[] = (int)$bu->id;
    }
    $baid = (int)$bas->id;
    $n = count($bstudents);
    $addcheck('burst cutoffdate', "SELECT cutoffdate FROM {assign} WHERE id = ?", [$baid], $bd['cutoffdate']);
    $addcheck('burst teamsubmission', "SELECT teamsubmission FROM {assign} WHERE id = ?", [$baid], 0);
    $addcheck('burst markingworkflow (grades released on save)', "SELECT markingworkflow FROM {assign} WHERE id = ?", [$baid], 0);
    $addcheck('burst students enrolled', "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
        WHERE e.courseid = ? AND ue.status = 0", [$bcourse->id], $n);
    $addcheck('burst submissions', "SELECT COUNT(1) FROM {assign_submission} WHERE assignment = ? AND latest = 1 AND status = 'submitted'",
        [$baid], $n);
    $addcheck('burst files', "SELECT COUNT(1) FROM {files} f JOIN {assign_submission} s ON s.id = f.itemid WHERE s.assignment = ?
        AND f.component = 'assignsubmission_file' AND f.filearea = 'submission_files' AND f.filename <> '.'", [$baid], $n * $nfiles);
    $addcheck('burst grades', "SELECT COUNT(1) FROM {assign_grades} WHERE assignment = ? AND grade = ?", [$baid, $bd['grade']], $n);
    $addcheck('burst grade item visible', "SELECT hidden FROM {grade_items} WHERE courseid = ? AND itemmodule = 'assign' AND iteminstance = ?
        AND itemnumber = 0", [$bcourse->id, $baid], 0);
    $m['burst'] = ['cmid' => (int)$bas->cmid, 'courseid' => (int)$bcourse->id, 'assignid' => $baid, 'students' => $bstudents,
        'files_per_submission' => $nfiles, 'grade' => $bd['grade'], 'cutoffdate' => $bd['cutoffdate']];
}

// --- one login-as event ---------------------------------------------------------------------------------
$lk = $catalogue['login_as']['admin_logs_in_as'];
$admin = get_admin();
\core\event\user_loggedinas::create([
    'objectid' => $u[$lk]->id, 'context' => context_system::instance(), 'relateduserid' => $u[$lk]->id,
    'other' => ['originalusername' => fullname($admin), 'loggedinasusername' => fullname($u[$lk])],
])->trigger();
get_log_manager()->dispose();   // flush the buffered standard-log writes now
$m['login_as'] = ['by' => 'admin', 'target_user' => $lk, 'targetuserid' => (int)$u[$lk]->id, 'eventname' => '\\core\\event\\user_loggedinas'];
$addcheck("login-as event for $lk", "SELECT COUNT(1) FROM {logstore_standard_log} WHERE eventname = ? AND relateduserid = ?", ['\\core\\event\\user_loggedinas', $u[$lk]->id], 1);

// --- canary registry: unique + present where claimed ---------------------------------------------------
foreach ($canaries as $tok => $where) {
    $addcheck("canary $tok present", "SELECT COUNT(1) FROM {" . $where['table'] . "} WHERE " . $DB->sql_like($where['field'], '?', false) . "", ['%' . $tok . '%'], 1);
}
// Canaries are hand-registered with their table/field; one of them (rubric levels' field is 'definition') is a
// text column, all others are char/text: LIKE works. Dedupe expectations: each token appears exactly once.

$m['canaries'] = $canaries;
$m['seeded_false'] = $skipped;
$m['checks'] = $checks;
check_dir_exists($outdir);
$manifestjson = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($manifestpath, $manifestjson) !== strlen($manifestjson)) {
    fwrite(STDERR, "manifest write failed: $manifestpath. Reset the environment (make moodleNN-reset) before re-seeding.\n");
    exit(1);
}
fwrite(STDERR, "seeded $tag: manifest written to $manifestpath\n");
$finish($checks, $skipped);

// ===============================================================================================
// Sample file builders (small, valid files; no external libs).
// ===============================================================================================
function eaa_build_sample_files(string $dir, string $tag): array {
    $out = [];
    // Text PDF.
    $text = "CANARY-PDFTEXT-$tag";
    $p = "$dir/report_text.pdf";
    file_put_contents($p, eaa_pdf_text($text));
    $out['pdf_text'] = ['path' => $p, 'mimetype' => 'application/pdf', 'canary' => $text];
    // Scanned PDF: one page with an image only, no text layer.
    $p = "$dir/report_scan.pdf";
    file_put_contents($p, eaa_pdf_scan());
    $out['pdf_scan'] = ['path' => $p, 'mimetype' => 'application/pdf', 'canary' => null];
    // DOCX.
    $t = "CANARY-DOCX-$tag";
    $p = "$dir/report.docx";
    eaa_zip($p, [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . $t . '</w:t></w:r></w:p></w:body></w:document>',
    ]);
    $out['docx'] = ['path' => $p, 'mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'canary' => $t];
    // ODT (mimetype entry must be first and stored).
    $t = "CANARY-ODT-$tag";
    $p = "$dir/report.odt";
    eaa_zip($p, [
        'mimetype' => 'application/vnd.oasis.opendocument.text',
        'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>',
        'content.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2"><office:body><office:text><text:p>' . $t . '</text:p></office:text></office:body></office:document-content>',
    ], true);
    $out['odt'] = ['path' => $p, 'mimetype' => 'application/vnd.oasis.opendocument.text', 'canary' => $t];
    // PPTX (master + layout + theme kept minimal).
    $t = "CANARY-PPTX-$tag";
    $p = "$dir/slides.pptx";
    $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    $tree = '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $relsxml = fn(array $r) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', array_map(fn($id, $x) => '<Relationship Id="' . $id . '" Type="' . $x[0] . '" Target="' . $x[1] . '"/>', array_keys($r), $r)) . '</Relationships>';
    eaa_zip($p, [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/><Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/><Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/><Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/><Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/></Types>',
        '_rels/.rels' => $relsxml(['rId1' => ["$rel/officeDocument", 'ppt/presentation.xml']]),
        'ppt/presentation.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><p:presentation $ns><p:sldMasterIdLst><p:sldMasterId id=\"2147483648\" r:id=\"rId1\"/></p:sldMasterIdLst><p:sldIdLst><p:sldId id=\"256\" r:id=\"rId2\"/></p:sldIdLst><p:sldSz cx=\"9144000\" cy=\"6858000\"/><p:notesSz cx=\"6858000\" cy=\"9144000\"/></p:presentation>",
        'ppt/_rels/presentation.xml.rels' => $relsxml(['rId1' => ["$rel/slideMaster", 'slideMasters/slideMaster1.xml'], 'rId2' => ["$rel/slide", 'slides/slide1.xml'], 'rId3' => ["$rel/theme", 'theme/theme1.xml']]),
        'ppt/slideMasters/slideMaster1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><p:sldMaster $ns><p:cSld><p:spTree>$tree</p:spTree></p:cSld><p:clrMap bg1=\"lt1\" tx1=\"dk1\" bg2=\"lt2\" tx2=\"dk2\" accent1=\"accent1\" accent2=\"accent2\" accent3=\"accent3\" accent4=\"accent4\" accent5=\"accent5\" accent6=\"accent6\" hlink=\"hlink\" folHlink=\"folHlink\"/><p:sldLayoutIdLst><p:sldLayoutId id=\"2147483649\" r:id=\"rId1\"/></p:sldLayoutIdLst></p:sldMaster>",
        'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $relsxml(['rId1' => ["$rel/slideLayout", '../slideLayouts/slideLayout1.xml'], 'rId2' => ["$rel/theme", '../theme/theme1.xml']]),
        'ppt/slideLayouts/slideLayout1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><p:sldLayout $ns type=\"blank\"><p:cSld><p:spTree>$tree</p:spTree></p:cSld></p:sldLayout>",
        'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $relsxml(['rId1' => ["$rel/slideMaster", '../slideMasters/slideMaster1.xml']]),
        'ppt/slides/slide1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><p:sld $ns><p:cSld><p:spTree>$tree<p:sp><p:nvSpPr><p:cNvPr id=\"2\" name=\"T\"/><p:cNvSpPr txBox=\"1\"/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x=\"457200\" y=\"457200\"/><a:ext cx=\"6000000\" cy=\"800000\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></p:spPr><p:txBody><a:bodyPr/><a:p><a:r><a:rPr lang=\"en-US\"/><a:t>$t</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>",
        'ppt/slides/_rels/slide1.xml.rels' => $relsxml(['rId1' => ["$rel/slideLayout", '../slideLayouts/slideLayout1.xml']]),
        'ppt/theme/theme1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="T"><a:themeElements><a:clrScheme name="C"><a:dk1><a:srgbClr val="000000"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="333333"/></a:dk2><a:lt2><a:srgbClr val="EEEEEE"/></a:lt2><a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2><a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4><a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6><a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme><a:fontScheme name="F"><a:majorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme><a:fmtScheme name="M"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>',
    ]);
    $out['pptx'] = ['path' => $p, 'mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'canary' => $t];
    return $out;
}

function eaa_zip(string $path, array $entries, bool $storefirst = false): void {
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) { throw new coding_exception("cannot create $path"); }
    $i = 0;
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
        if ($storefirst && $i === 0) { $zip->setCompressionName($name, ZipArchive::CM_STORE); }
        $i++;
    }
    $zip->close();
}

function eaa_pdf_build(array $objects): string {
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) { $pdf .= sprintf("%010d 00000 n \n", $off); }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

function eaa_pdf_text(string $text): string {
    $stream = "BT /F1 18 Tf 72 720 Td ($text) Tj ET";
    return eaa_pdf_build([
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ]);
}

// A "scan": one page whose only content is an 8x8 raster image (no text operators, no font).
function eaa_pdf_scan(): string {
    $hex = '';
    for ($y = 0; $y < 8; $y++) { for ($x = 0; $x < 8; $x++) { $v = (($x + $y) % 2) ? 'FFFFFF' : '202020'; $hex .= $v; } }
    $hex .= '>';
    $content = 'q 400 0 0 400 106 196 cm /Im0 Do Q';
    return eaa_pdf_build([
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>',
        "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream",
        "<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /ASCIIHexDecode /Length " . strlen($hex) . " >>\nstream\n$hex\nendstream",
    ]);
}
