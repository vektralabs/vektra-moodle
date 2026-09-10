// Probes for the course-scoping logic (FEAT-010) and the nodes it touches.
//
//   node n8n/tests/scope-by-block.mjs
//
// The workflow's JavaScript lives inside a JSON string, where nothing can reach
// it: no linter, no type checker, no test runner. These probes pull each Code
// node's source straight out of the template and run it with n8n's globals
// stubbed, so a change to the template is checked against the behaviour it is
// supposed to have rather than against a copy that drifts.
//
// Nothing here touches Moodle, Vektra or n8n. The end-to-end runs that do are
// recorded in .s2s/BACKLOG.md under FEAT-010.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const WF = new URL('../workflows/moodle-ingest.json', import.meta.url);
const wf = JSON.parse(fs.readFileSync(WF, 'utf8'));
const codeOf = (name) => wf.nodes.find(n => n.name === name).parameters.jsCode;

let pass = 0, fail = 0;
const check = (label, fn) => {
  try { fn(); console.log(`  PASS  ${label}`); pass++; }
  catch (e) { console.log(`  FAIL  ${label}\n        ${e.message}`); fail++; }
};
const eq = (a, b, m) => { const A = JSON.stringify(a), B = JSON.stringify(b);
  if (A !== B) throw new Error(`${m || ''} expected ${B}, got ${A}`); };

// --- runner: executes a Code node body with n8n's globals injected ---
function runNode(name, { nodes = {}, input = [], env = {} } = {}) {
  const $ = (n) => {
    if (!(n in nodes)) throw new Error(`probe: node '${n}' not stubbed`);
    const items = nodes[n];
    return { first: () => items[0], all: () => items, item: items[0] };
  };
  const $input = { all: () => input, first: () => input[0] };
  const logs = [];
  const fakeConsole = { log: (...a) => logs.push(a.join(' ')) };
  const body = new Function('$', '$input', '$env', 'require', 'console',
    `${codeOf(name)}`);
  const out = body($, $input, env, (m) => { if (m === 'fs') return fs; throw new Error('blocked: ' + m); }, fakeConsole);
  return { out, logs };
}

function stateFile(contents) {
  const p = path.join(os.tmpdir(), `probe-state-${Math.random().toString(36).slice(2)}.json`);
  fs.writeFileSync(p, JSON.stringify(contents));
  return p;
}
const cfg = (statePath) => [{ json: { moodleUrl: 'http://m', wsToken: 't', statePath, optOutTag: '[no-ai]' } }];
const okBody = (ids) => ({ statusCode: 200, body: { blocks: ids.map(id => ({ instanceid: id, name: 'vektra', region: 'side-pre', visible: true })), warnings: [] } });
const emptyBody = () => ({ statusCode: 200, body: { blocks: [], warnings: [] } });
const excBody = (code) => ({ statusCode: 200, body: { exception: 'dml_missing_record_exception', errorcode: code, message: "Can't find data record in database." } });

const scope = (courses, responses, state) => {
  const sp = stateFile(state ?? { courseFiles: {} });
  const r = runNode('Scope Courses', {
    nodes: { Config: cfg(sp), 'Filter Courses': courses.map(c => ({ json: c })) },
    input: responses.map(b => ({ json: b })),
  });
  fs.unlinkSync(sp);
  return r;
};
const names = (out) => out.map(i => i.json.shortname);
const purged = (out) => out.filter(i => i.json._outOfScope === true).map(i => i.json.shortname);

console.log('\nScope Courses');

check('corso col blocco proprio entra in scope', () => {
  const { out } = scope([{ id: 2, shortname: 'test-corso' }], [okBody([9])]);
  eq(names(out), ['test-corso']); eq(purged(out), []);
});

check('corso senza blocco e mai ingerito viene scartato', () => {
  const { out } = scope([{ id: 5, shortname: 'storia-ambiente' }], [emptyBody()]);
  eq(names(out), []);
});

check('corso senza blocco ma nell\'indice viene marcato per la potatura', () => {
  const { out } = scope(
    [{ id: 5, shortname: 'storia-ambiente' }], [emptyBody()],
    { courseFiles: { 'storia-ambiente': { 'http://f/1': { document_id: 'd1', filename: 'a.pdf' } } } });
  eq(purged(out), ['storia-ambiente']);
});

check('namespace risolto con lo stesso slug di Extract Files (accenti, spazi)', () => {
  const { out } = scope(
    [{ id: 7, shortname: 'Pedagogía Generale 2026' }], [emptyBody()],
    { courseFiles: { 'pedagogia-generale-2026': { 'http://f/1': { document_id: 'd1' } } } });
  eq(purged(out), ['Pedagogía Generale 2026'], 'lo slug non ha trovato lo state:');
});

check('namespace vuoto nello state NON fa scattare la potatura', () => {
  const { out } = scope(
    [{ id: 5, shortname: 'storia-ambiente' }], [emptyBody()],
    { courseFiles: { 'storia-ambiente': {} } });
  eq(names(out), [], 'un namespace gia svuotato non deve ripassare');
});

check('errore WS congela il corso: ne ingerito ne potato', () => {
  const { out, logs } = scope(
    [{ id: 3, shortname: 'abilitazione' }, { id: 2, shortname: 'test-corso' }],
    [excBody('invalidrecordunknown'), okBody([9])],
    { courseFiles: { abilitazione: { 'http://f/1': { document_id: 'd1' } } } });
  eq(names(out), ['test-corso'], 'il corso in errore non deve comparire:');
  eq(purged(out), [], 'un corso in errore non va MAI potato:');
  if (!logs.some(l => l.includes('left untouched'))) throw new Error('nessun log del congelamento');
});

check('HTTP non-2xx congela il corso (accanto a uno sano)', () => {
  const { out } = scope(
    [{ id: 3, shortname: 'abilitazione' }, { id: 2, shortname: 'test-corso' }],
    [{ statusCode: 503, body: 'gateway' }, okBody([9])],
    { courseFiles: { abilitazione: { 'http://f/1': { document_id: 'd1' } } } });
  eq(names(out), ['test-corso'], 'il 503 non deve ne ingerire ne potare:');
  eq(purged(out), []);
});

check('body JSON come stringa viene comunque interpretato', () => {
  const { out } = scope([{ id: 2, shortname: 'test-corso' }],
    [{ statusCode: 200, body: JSON.stringify({ blocks: [{ instanceid: 9, name: 'vektra' }], warnings: [] }) }]);
  eq(names(out), ['test-corso']);
});

check('blocco ereditato dal sito NON mette in scope i corsi', () => {
  const { out, logs } = scope(
    [{ id: 2, shortname: 'a' }, { id: 3, shortname: 'b' }, { id: 4, shortname: 'c' }],
    [okBody([10]), okBody([10]), okBody([10, 6])]);
  eq(names(out), ['c'], 'solo il corso col blocco proprio (6) resta:');
  if (!logs.some(l => l.includes('inherited'))) throw new Error('nessun avviso sull\'ereditarieta');
});

check('blocco proprio + ereditato: vince il proprio', () => {
  const { out } = scope(
    [{ id: 2, shortname: 'a' }, { id: 4, shortname: 'c' }],
    [okBody([10]), okBody([10, 6])]);
  eq(names(out), ['c']);
});

check('corso solo nella sua categoria: viene indicizzato ma segnalato', () => {
  const { out, logs } = scope(
    [{ id: 12, shortname: 'analisi-2', categoryid: 7 }], [okBody([44])]);
  eq(names(out), ['analisi-2'], 'va comunque indicizzato, non bloccato:');
  if (!logs.some(l => l.includes('only course in its category')))
    throw new Error('nessun avviso sul corso ambiguo');
  if (!logs.some(l => l.includes('could not be told apart')))
    throw new Error('il riepilogo non riporta il conteggio ambiguo');
});

check('corso con altri nella stessa categoria: nessun avviso', () => {
  const { out, logs } = scope(
    [{ id: 12, shortname: 'analisi-2', categoryid: 7 }, { id: 13, shortname: 'fisica-1', categoryid: 7 }],
    [okBody([44]), emptyBody()]);
  eq(names(out), ['analisi-2']);
  if (logs.some(l => l.includes('only course in its category')))
    throw new Error('avviso non richiesto: la categoria ne ha due');
});

check('corso senza categoryid non manda in errore il conteggio', () => {
  const { out } = scope([{ id: 12, shortname: 'analisi-2' }], [okBody([44])]);
  eq(names(out), ['analisi-2']);
});

check('blocco di un altro plugin non conta', () => {
  const { out } = scope([{ id: 2, shortname: 'a' }],
    [{ statusCode: 200, body: { blocks: [{ instanceid: 1, name: 'html' }, { instanceid: 2, name: 'calendar_month' }], warnings: [] } }]);
  eq(names(out), []);
});

check('tutte le chiamate in errore: solleva eccezione', () => {
  let threw = null;
  try { scope([{ id: 2, shortname: 'a' }, { id: 3, shortname: 'b' }], [excBody('x'), excBody('x')]); }
  catch (e) { threw = e.message; }
  if (!threw || !threw.includes('core_block_get_course_blocks')) throw new Error(`atteso throw sulla funzione WS, ottenuto: ${threw}`);
});

check('solo blocco site-wide + corsi indicizzati: rifiuta di potare', () => {
  let threw = null;
  try {
    scope([{ id: 2, shortname: 'a' }, { id: 3, shortname: 'b' }], [okBody([10]), okBody([10])],
      { courseFiles: { a: { 'u': { document_id: 'd' } }, b: { 'u': { document_id: 'd' } } } });
  } catch (e) { threw = e.message; }
  if (!threw || !threw.includes('site-wide')) throw new Error(`atteso rifiuto di potare, ottenuto: ${threw}`);
});

check('disallineamento risposte/corsi: solleva eccezione', () => {
  let threw = null;
  try { scope([{ id: 2, shortname: 'a' }, { id: 3, shortname: 'b' }], [okBody([9])]); }
  catch (e) { threw = e.message; }
  if (!threw || !threw.includes('Refusing to pair')) throw new Error(`atteso throw sul pairing, ottenuto: ${threw}`);
});

check('JSON reale mooc2: corso 2 in scope, corso 5 fuori', () => {
  const c2 = { statusCode: 200, body: JSON.parse('{"blocks":[{"instanceid":9,"name":"vektra","region":"side-pre","positionid":null,"collapsible":true,"dockable":false,"weight":0,"visible":true}],"warnings":[]}') };
  const c5 = { statusCode: 200, body: JSON.parse('{"blocks":[],"warnings":[]}') };
  const { out } = scope([{ id: 2, shortname: 'test-corso' }, { id: 5, shortname: 'storia-ambiente' }], [c2, c5],
    { courseFiles: { 'storia-ambiente': { 'http://f/1': { document_id: 'd1' } } } });
  eq(names(out), ['test-corso', 'storia-ambiente']);
  eq(purged(out), ['storia-ambiente']);
});

// ---------------- Dedup & Diff ----------------
console.log('\nDedup & Diff');

const dedup = (input, state, env = {}) => {
  const sp = stateFile(state);
  const r = runNode('Dedup & Diff', { nodes: { Config: cfg(sp) }, input: [{ json: input }], env });
  fs.unlinkSync(sp);
  return r.out[0].json;
};
const stored5 = { courseFiles: { ns: Object.fromEntries(
  [1,2,3,4,5].map(i => [`http://f/${i}`, { document_id: `d${i}`, filename: `f${i}.pdf`, timemodified: 1 }])) } };

check('la rete anti-avaria protegge un corso normale svuotato', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', files: [], modulesSeen: 5, modulesExcluded: 0 }, stored5);
  eq(r.toDelete.length, 0, 'un corso vuoto per errore non va potato:');
  eq(r._emptyCourseSafe, true);
});

check('un corso fuori scope aggira la rete e viene potato del tutto', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', files: [], modulesSeen: 0, modulesExcluded: 0, _outOfScope: true }, stored5);
  eq(r.toDelete.length, 5, 'devono uscire tutti i file:');
  eq(r.summary.removed, 5);
  eq(r.toDelete.map(d => d.document_id), ['d1','d2','d3','d4','d5']);
});

check('la potatura fuori scope propaga il flag al sommario', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', files: [], _outOfScope: true }, stored5);
  eq(r._outOfScope, true);
});

check('_moodleError vince su tutto: nessuna cancellazione', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', files: [], _moodleError: true, errorDetail: 'x' }, stored5);
  eq(r.toDelete.length, 0);
});

check('un corso in scope resta invariato dal cambiamento', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 5, modulesExcluded: 0,
    files: [1,2,3,4,5].map(i => ({ fileurl: `http://f/${i}`, filename: `f${i}.pdf`, timemodified: 1 })) }, stored5);
  eq(r.summary, { new: 0, updated: 0, removed: 0, unchanged: 5 });
});

// ---------------- Extract Files ----------------
console.log('\nExtract Files');

const extract = (courseJson, contents) => runNode('Extract Files', {
  nodes: { Config: cfg('/dev/null'), 'Loop Courses': [{ json: courseJson }] },
  input: [{ json: { body: contents } }],
}).out[0].json;

check('corso fuori scope esce subito, senza leggere i contenuti', () => {
  const r = extract({ id: 5, shortname: 'storia-ambiente', _outOfScope: true }, 'contenuti irrilevanti');
  eq(r, { courseId: 5, namespace: 'storia-ambiente', files: [], modulesSeen: 0, modulesExcluded: 0, _outOfScope: true });
});

check('fuori scope funziona quando l\'IF salta la chiamata contenuti', () => {
  // `Out of scope?` routes the course item itself here, not a Moodle response.
  const course = { id: 5, shortname: 'storia-ambiente', _outOfScope: true };
  const r = runNode('Extract Files', {
    nodes: { Config: cfg('/dev/null'), 'Loop Courses': [{ json: course }] },
    input: [{ json: course }],
  }).out[0].json;
  eq(r, { courseId: 5, namespace: 'storia-ambiente', files: [], modulesSeen: 0, modulesExcluded: 0, _outOfScope: true });
});

check('fuori scope vince anche se Moodle ha risposto male', () => {
  const r = extract({ id: 5, shortname: 'storia-ambiente', _outOfScope: true }, { exception: 'boom' });
  eq(r._outOfScope, true);
  if (r._moodleError) throw new Error('_moodleError bloccherebbe la potatura');
});

check('corso in scope estrae i file come prima', () => {
  const r = extract({ id: 3, shortname: 'psicologia-generale' }, [{ name: 'S1', modules: [
    { id: 70, name: 'Lezione 1', modname: 'resource', visible: 1, contents: [
      { type: 'file', filename: 'a.pdf', mimetype: 'application/pdf', fileurl: 'http://f/a', filesize: 1, timemodified: 5 }] } ] }]);
  eq(r.files.length, 1); eq(r.files[0].uniqueFilename, 'mod70_a.pdf'); eq(r._outOfScope, undefined);
});

check('opt-out continua a funzionare in un corso in scope', () => {
  const r = extract({ id: 3, shortname: 'psi' }, [{ name: 'S1', modules: [
    { id: 70, name: 'Lezione [NO-AI]', modname: 'resource', visible: 1, contents: [
      { type: 'file', filename: 'a.pdf', mimetype: 'application/pdf', fileurl: 'http://f/a', timemodified: 5 }] } ] }]);
  eq(r.files.length, 0); eq(r.modulesExcluded, 1);
});

// ---------------- No Changes ----------------
console.log('\nNo Changes');

const noChanges = (input) => runNode('No Changes', { input: [{ json: input }] }).out[0].json;

check('corso potato con tutte le cancellazioni riuscite: risulta uscito', () => {
  const r = noChanges({ courseId: 5, namespace: 'ns', _outOfScope: true, unchanged: [],
    deleteResults: [{ filename: 'a.pdf', status: 'deleted' }, { filename: 'b.pdf', status: 'deleted' }] });
  eq(r._outOfScope, true);
});

check('una cancellazione fallita NON fa contare il corso come uscito', () => {
  const r = noChanges({ courseId: 5, namespace: 'ns', _outOfScope: true, unchanged: [],
    deleteResults: [{ filename: 'a.pdf', status: 'deleted' },
                    { filename: 'b.pdf', status: 'delete_failed', error: 'HTTP 500' }] });
  eq(r._outOfScope, false, 'i documenti sono ancora li, il corso non e uscito:');
  eq(r.courseResults.filter(x => x.status === 'failed').length, 1);
});

check('un corso in scope non viene mai marcato come uscito', () => {
  const r = noChanges({ courseId: 3, namespace: 'ns', unchanged: [{ filename: 'a.pdf' }], deleteResults: [] });
  eq(r._outOfScope, false);
});

// ---------------- Ingestion Summary ----------------
console.log('\nIngestion Summary');

const summarize = (items) => runNode('Ingestion Summary', { input: items.map(j => ({ json: j })) }).out[0].json;

check('conta i corsi usciti dall\'indice', () => {
  const r = summarize([
    { _outOfScope: true, courseResults: [
      { filename: 'a.pdf', action: 'removed', status: 'completed' },
      { filename: 'b.pdf', action: 'removed', status: 'completed' }] },
    { courseResults: [{ file_name: 'c.pdf', action: 'new', status: 'completed' }] },
  ]);
  eq(r.totals.purgedCourses, 1);
  eq(r.totals.removed, 2);
  eq(r.totals.new, 1);
  if (!r.summary.includes('1 course(s) left the index')) throw new Error(`sommario muto: ${r.summary}`);
});

check('un corso con cancellazioni fallite non entra in purgedCourses', () => {
  const r = summarize([{ _outOfScope: false, courseResults: [
    { file_name: 'a.pdf', action: 'removed', status: 'completed' },
    { file_name: 'b.pdf', action: 'removed', status: 'failed', error: 'HTTP 500' }] }]);
  eq(r.totals.purgedCourses, 0);
  eq(r.totals.failed, 1);
});

check('senza potature il sommario resta come prima', () => {
  const r = summarize([{ courseResults: [{ file_name: 'c.pdf', action: 'new', status: 'completed' }] }]);
  eq(r.totals.purgedCourses, 0);
  if (r.summary.includes('left the index')) throw new Error('nota di potatura non richiesta');
});

check('unchanged conta prima di skipped (regressione FEAT-004)', () => {
  const r = summarize([{ courseResults: [{ file_name: 'a.pdf', action: 'unchanged', status: 'skipped' }] }]);
  eq(r.totals.unchanged, 1); eq(r.totals.skipped, 0);
});

console.log(`\n${pass} passati, ${fail} falliti\n`);
process.exit(fail ? 1 : 0);
