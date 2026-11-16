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
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

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
  const out = body($, $input, env, (m) => { if (m === 'fs') return fs; if (m === 'url') return { URL }; throw new Error('blocked: ' + m); }, fakeConsole);
  return { out, logs };
}

function stateFile(contents) {
  const p = path.join(os.tmpdir(), `probe-state-${Math.random().toString(36).slice(2)}.json`);
  fs.writeFileSync(p, JSON.stringify(contents));
  return p;
}
const cfg = (statePath) => [{ json: { moodleUrl: 'http://m', wsToken: 't', statePath, optOutTag: '[no-ai]', lmsSite: 'http://m' } }];
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

// ---------------- Config (lms_site) ----------------
console.log('\nConfig (lms_site)');

// Runs a Config node source (template or a built instance) with the given env.
const runConfig = (env, code = codeOf('Config')) => {
  const body = new Function('$env', 'require', code);
  return body({ MOODLE_WS_TOKEN: 't', STATE_FILE_PATH: '/s', ...env },
    (m) => { if (m === 'url') return { URL }; throw new Error('blocked: ' + m); })[0].json;
};
const siteOf = (url) => runConfig({ MOODLE_URL: url }).lmsSite;
const refuses = (env, code) => {
  let threw = null;
  try { runConfig(env, code); } catch (e) { threw = e.message; }
  if (!threw || !threw.includes('not a valid http(s) URL')) throw new Error(`atteso rifiuto, ottenuto: ${threw}`);
};

for (const [input, expected] of [
  ['HTTPS://Moodle.Example.org:443/lms/', 'https://moodle.example.org/lms'],
  ['http://h:8052', 'http://h:8052'],
  ['http://H:80', 'http://h'],
  ['https://h/', 'https://h'],
  ['https://h', 'https://h'],
  ['HTTP://MOODLE.EXAMPLE.ORG/LMS', 'http://moodle.example.org/LMS'],
  ['https://h:443', 'https://h'],
  ['http://h:443', 'http://h:443'],
  ['https://h:80', 'https://h:80'],
  ['https://h:8443/a/b/', 'https://h:8443/a/b'],
  ['https://h/lms', 'https://h/lms'],
  ['https://h/lms//', 'https://h/lms'],
  ['https://h/lms/?x=1#frag', 'https://h/lms'],
  ['https://h?x=1', 'https://h'],
  ['https://h/#frag', 'https://h'],
  ['  https://h/lms/  ', 'https://h/lms'],
]) {
  check(`normalizza ${JSON.stringify(input)} -> ${expected}`, () => eq(siteOf(input), expected));
}

check('URL non valido: Config ferma il run', () => refuses({ MOODLE_URL: 'not a url' }));
check('schema non http(s): Config ferma il run', () => refuses({ MOODLE_URL: 'ftp://h/lms' }));
check('MOODLE_URL mancante: Config ferma il run', () => {
  let threw = null;
  try { runConfig({}); } catch (e) { threw = e.message; }
  if (!threw || !threw.includes('MOODLE_URL')) throw new Error(`atteso rifiuto, ottenuto: ${threw}`);
});
check('LMS_SITE_URL ha la precedenza su MOODLE_URL', () => {
  const c = runConfig({ MOODLE_URL: 'http://localhost:8082', LMS_SITE_URL: 'HTTP://Moodle52/' });
  eq(c.lmsSite, 'http://moodle52');
  eq(c.moodleUrl, 'http://localhost:8082', 'i download devono restare su MOODLE_URL:');
});
check('LMS_SITE_URL vuoto ricade su MOODLE_URL', () => {
  eq(runConfig({ MOODLE_URL: 'http://localhost:8082', LMS_SITE_URL: '  ' }).lmsSite, 'http://localhost:8082');
});
check('LMS_SITE_URL non valido ferma il run anche con MOODLE_URL valido', () =>
  refuses({ MOODLE_URL: 'http://localhost:8082', LMS_SITE_URL: 'moodle52' }));

// Each built instance must read its OWN prefixed variables.
const builtConfig = (inst) => {
  const out = execFileSync(process.execPath,
    [fileURLToPath(new URL('../scripts/build-instance.mjs', import.meta.url)), inst], { encoding: 'utf8' });
  return JSON.parse(out).nodes.find(n => n.name === 'Config').parameters.jsCode;
};
const allInstances = fs.readdirSync(new URL('../instances/', import.meta.url))
  .filter(f => f.endsWith('.json')).map(f => f.replace(/\.json$/, ''));
for (const inst of allInstances) {
  const prefix = JSON.parse(fs.readFileSync(new URL(`../instances/${inst}.json`, import.meta.url), 'utf8')).envPrefix ?? '';
  check(`istanza ${inst}: lms_site da ${prefix}MOODLE_URL / ${prefix}LMS_SITE_URL`, () => {
    const code = builtConfig(inst);
    // Decoys under the other names prove the instance ignores them.
    const decoys = { MOODLE_URL: 'http://main-decoy', LMS_SITE_URL: 'http://main-site-decoy',
      MOOC_MOODLE_URL: 'http://mooc-decoy', MOOC_LMS_SITE_URL: 'http://mooc-site-decoy',
      ELEARNING_MOODLE_URL: 'http://el-decoy', ELEARNING_LMS_SITE_URL: 'http://el-site-decoy' };
    const own = { [`${prefix}MOODLE_URL`]: `http://${inst}.example:8052/`, [`${prefix}MOODLE_WS_TOKEN`]: 't', [`${prefix}STATE_FILE_PATH`]: '/s' };
    const fallback = runConfig({ ...decoys, ...own, [`${prefix}LMS_SITE_URL`]: '' }, code);
    eq(fallback.lmsSite, `http://${inst}.example:8052`);
    const override = runConfig({ ...decoys, ...own, [`${prefix}LMS_SITE_URL`]: `HTTP://${inst.toUpperCase()}-SITE/` }, code);
    eq(override.lmsSite, `http://${inst}-site`);
    eq(override.moodleUrl, `http://${inst}.example:8052`);
  });
}
check('le istanze in n8n/instances sono almeno main, mooc, elearning', () => {
  for (const i of ['main', 'mooc', 'elearning']) if (!allInstances.includes(i)) throw new Error(`manca ${i}`);
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
  [1,2,3,4,5].map(i => [`http://f/${i}`, { document_id: `d${i}`, filename: `f${i}.pdf`, timemodified: 1, cmid: 70 + i, lms_site: 'http://m' }])) } };

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
    files: [1,2,3,4,5].map(i => ({ fileurl: `http://f/${i}`, filename: `f${i}.pdf`, timemodified: 1, moduleId: 70 + i })) }, stored5);
  eq(r.summary, { new: 0, updated: 0, removed: 0, unchanged: 5 });
});

// cmid is metadata, like hidden_from_students: changing it touches no file, so
// it has to be part of the change signature or existing documents never get it.
const oneFile = (over = {}) => ({ fileurl: 'http://f/1', filename: 'f1.pdf', timemodified: 1, moduleId: 71, hidden: false, ...over });
const oneStored = (over = {}) => ({ courseFiles: { ns: { 'http://f/1': { document_id: 'd1', filename: 'mod71_f1.pdf', timemodified: 1, hidden: false, lms_site: 'http://m', ...over } } } });

check('cmid: documento ingerito prima di cmid viene re-ingerito una volta', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, oneStored());
  eq(r.summary, { new: 0, updated: 1, removed: 0, unchanged: 0 });
  eq(r.toIngest[0].old_document_id, 'd1', 'il vecchio documento va cancellato prima:');
});

check('cmid: con cmid gia nello state il file resta invariato', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, oneStored({ cmid: 71 }));
  eq(r.summary, { new: 0, updated: 0, removed: 0, unchanged: 1 });
});

check('cmid: un cmid diverso nello state fa re-ingerire', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, oneStored({ cmid: 99 }));
  eq(r.summary.updated, 1);
});

check('lms_site: state senza lms_site (cmid uguale) fa re-ingerire una volta', () => {
  const st = oneStored({ cmid: 71 }); delete st.courseFiles.ns['http://f/1'].lms_site;
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, st);
  eq(r.summary.updated, 1);
  eq(r.toIngest[0].old_document_id, 'd1');
});

check('lms_site: valore diverso nello state fa re-ingerire', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, oneStored({ cmid: 71, lms_site: 'http://altro' }));
  eq(r.summary.updated, 1);
});

check('lms_site: stessi cmid e lms_site restano invariati', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile()] }, oneStored({ cmid: 71, lms_site: 'http://m' }));
  eq(r.summary, { new: 0, updated: 0, removed: 0, unchanged: 1 });
});

check('cmid: il flip di visibilita resta un update come prima', () => {
  const r = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1, files: [oneFile({ hidden: true })] }, oneStored({ cmid: 71 }));
  eq(r.summary.updated, 1);
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

check('ogni file estratto porta il moduleId intero (sorgente di cmid)', () => {
  const r = extract({ id: 3, shortname: 'psi' }, [{ name: 'S1', modules: [
    { id: 70, name: 'Lezione 1', modname: 'resource', visible: 1, contents: [
      { type: 'file', filename: 'a.pdf', mimetype: 'application/pdf', fileurl: 'http://f/a', timemodified: 5 },
      { type: 'file', filename: 'b.docx', mimetype: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', fileurl: 'http://f/b', timemodified: 5 }] },
    { id: 81, name: 'Video', modname: 'page', visible: 0, contents: [
      { type: 'file', filename: 'index.html', mimetype: null, fileurl: 'http://f/i', timemodified: 5 },
      { type: 'file', filename: 's.pdf', mimetype: 'application/pdf', fileurl: 'http://f/s', timemodified: 5 }] } ] }]);
  eq(r.files.map(f => [f.uniqueFilename, f.moduleId]),
    [['mod70_a.pdf', 70], ['mod70_b.docx', 70], ['mod81_transcript.md', 81], ['mod81_s.pdf', 81]]);
  for (const f of r.files) {
    if (!Number.isInteger(f.moduleId)) throw new Error(`moduleId non intero su ${f.uniqueFilename}`);
    if (!f.uniqueFilename.startsWith(`mod${f.moduleId}_`)) throw new Error(`moduleId e prefisso del nome divergono: ${f.uniqueFilename}`);
  }
});

check('namespace resta lo slug dello shortname', () => {
  const r = extract({ id: 7, shortname: 'Pedagogía Generale 2026' }, []);
  eq(r.namespace, 'pedagogia-generale-2026');
});

// ---------------- Process Single File ----------------
// Async: collected here, run at the end of the file.

// The node is async and talks HTTP through require('http'). The fake records
// every request and answers by URL, so the multipart body sent to /ingest can
// be inspected without a network.
async function processFile(file, { stateContents = { courseFiles: {} }, ingest = { status: 'new', document_id: 'new-doc', chunk_count: 3 }, cfgOver = {} } = {}) {
  const sp = stateFile(stateContents);
  const requests = [];
  const fakeHttp = { request(opts, cb) {
    const chunks = [];
    const req = {
      on() { return req; }, setTimeout() { return req; },
      write(b) { chunks.push(Buffer.isBuffer(b) ? b : Buffer.from(b)); },
      end() {
        const body = Buffer.concat(chunks);
        requests.push({ method: opts.method, path: opts.path, body });
        let status = 200, ct = 'application/pdf', resp = Buffer.from('%PDF-fake');
        if (opts.path.startsWith('/api/v1/ingest')) { ct = 'application/json'; resp = Buffer.from(JSON.stringify(ingest)); }
        else if (opts.path.startsWith('/api/v1/documents')) { ct = 'application/json'; resp = Buffer.from('{}'); }
        else if (opts.path.startsWith('/video')) { ct = 'text/html'; resp = Buffer.from('<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>'); }
        else if (opts.path.startsWith('/api/v1/transcript')) { ct = 'text/plain'; resp = Buffer.from('testo di prova'); }
        const res = { statusCode: status, headers: { 'content-type': ct }, on(ev, h) {
          if (ev === 'data') h(resp); if (ev === 'end') setImmediate(h); return res; } };
        setImmediate(() => cb(res));
      } };
    return req;
  } };
  const req = (m) => { if (m === 'fs') return fs; if (m === 'http' || m === 'https') return fakeHttp; if (m === 'url') return { URL }; throw new Error('blocked: ' + m); };
  const $ = (n) => { if (n !== 'Config') throw new Error(`probe: node '${n}' not stubbed`);
    const items = [{ json: { ...cfg(sp)[0].json, ...cfgOver } }]; return { first: () => items[0], all: () => items, item: items[0] }; };
  const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
  const body = new AsyncFunction('$', '$input', '$env', 'require', 'console', codeOf('Process Single File'));
  const out = await body($, { first: () => ({ json: file }), all: () => [{ json: file }] },
    { VEKTRA_API_URL: 'http://vektra', VEKTRA_API_KEY: 'k', YTDLP_API_URL: 'http://yt', YTDLP_API_KEY: 'y' }, req, { log() {} });
  const state = JSON.parse(fs.readFileSync(sp, 'utf8'));
  fs.unlinkSync(sp);
  const ingestReq = requests.find(r => r.path.startsWith('/api/v1/ingest'));
  let metadata = null;
  if (ingestReq) {
    const m = ingestReq.body.toString('latin1').match(/name="metadata"\r\n\r\n(\{[^\r]*\})/);
    metadata = m ? JSON.parse(m[1]) : null;
  }
  return { out: out[0].json, requests, ingestReq, metadata, state };
}
const pfile = (over = {}) => ({ action: 'new', namespace: 'ns', courseId: 9, fileurl: 'http://moodle/f/1',
  filename: 'a.pdf', uniqueFilename: 'mod71_a.pdf', mimetype: 'application/pdf', timemodified: 1,
  moduleId: 71, moduleName: 'Lezione 1', hidden: false, ...over });

const asyncChecks = [];
const acheck = (label, fn) => asyncChecks.push([label, fn]);

acheck('modulo visibile: metadata contiene cmid intero e lms_site', async () => {
  const r = await processFile(pfile());
  eq(r.out.status, 'completed');
  eq(r.metadata, { cmid: 71, lms_site: 'http://m' });
});

acheck('modulo nascosto: metadata contiene cmid, lms_site e hidden_from_students', async () => {
  const r = await processFile(pfile({ hidden: true }));
  eq(r.metadata, { cmid: 71, lms_site: 'http://m', hidden_from_students: true });
});

acheck('moduleId stringa viene inviato come intero', async () => {
  const r = await processFile(pfile({ moduleId: '71' }));
  eq(r.metadata, { cmid: 71, lms_site: 'http://m' });
});

acheck('namespace della richiesta di ingest invariato', async () => {
  const r = await processFile(pfile({ namespace: 'psicologia-generale' }));
  eq(r.ingestReq.path, '/api/v1/ingest?namespace=psicologia-generale');
});

acheck('lo state registra cmid e lms_site, cosi il giro dopo il file e invariato', async () => {
  const r = await processFile(pfile());
  eq(r.state.courseFiles.ns['http://moodle/f/1'].cmid, 71);
  eq(r.state.courseFiles.ns['http://moodle/f/1'].lms_site, 'http://m');
  const d = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1,
    files: [{ fileurl: 'http://moodle/f/1', filename: 'a.pdf', timemodified: 1, moduleId: 71, hidden: false }] }, r.state);
  eq(d.summary, { new: 0, updated: 0, removed: 0, unchanged: 1 });
});

acheck('re-ingest da state senza cmid: cancella il vecchio, invia cmid, converge', async () => {
  // State as written by the workflow before cmid existed: no cmid key.
  const pre = { courseFiles: { ns: { 'http://moodle/f/1': { document_id: 'd1', filename: 'mod71_a.pdf', timemodified: 1, hidden: false } } } };
  const d1 = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1,
    files: [{ fileurl: 'http://moodle/f/1', filename: 'a.pdf', timemodified: 1, moduleId: 71, hidden: false }] }, pre);
  eq(d1.summary.updated, 1);
  const r = await processFile(pfile({ ...d1.toIngest[0], uniqueFilename: 'mod71_a.pdf', mimetype: 'application/pdf' }), { stateContents: pre });
  const del = r.requests.find(q => q.method === 'DELETE');
  if (!del) throw new Error('il vecchio documento non e stato cancellato');
  eq(JSON.parse(del.body.toString()).document_ids, ['d1']);
  eq(r.metadata, { cmid: 71, lms_site: 'http://m' });
  const d2 = dedup({ courseId: 9, namespace: 'ns', modulesSeen: 1,
    files: [{ fileurl: 'http://moodle/f/1', filename: 'a.pdf', timemodified: 1, moduleId: 71, hidden: false }] }, r.state);
  eq(d2.summary.unchanged, 1, 'dopo il re-ingest non deve ripetersi:');
});

acheck('lms_site arriva da Config (es. override LMS_SITE_URL)', async () => {
  const r = await processFile(pfile({ hidden: true }), { cfgOver: { lmsSite: 'http://moodle52' } });
  eq(r.metadata, { cmid: 71, lms_site: 'http://moodle52', hidden_from_students: true });
  eq(r.state.courseFiles.ns['http://moodle/f/1'].lms_site, 'http://moodle52');
});

acheck('lms_site mancante: fallisce senza chiamare Vektra', async () => {
  const r = await processFile(pfile(), { cfgOver: { lmsSite: undefined } });
  eq(r.out.status, 'failed');
  eq(r.requests.length, 0);
});

acheck('ogni documento estratto (visibile, nascosto, pagina) riceve cmid e lms_site', async () => {
  const ex = extract({ id: 3, shortname: 'psi' }, [{ name: 'S1', modules: [
    { id: 70, name: 'Lezione 1', modname: 'resource', visible: 1, contents: [
      { type: 'file', filename: 'a.pdf', mimetype: 'application/pdf', fileurl: 'http://f/a', timemodified: 5 }] },
    { id: 72, name: 'Lezione 2', modname: 'resource', visible: 0, contents: [
      { type: 'file', filename: 'b.pdf', mimetype: 'application/pdf', fileurl: 'http://f/b', timemodified: 5 }] },
    { id: 81, name: 'Slide', modname: 'page', visible: 0, contents: [
      { type: 'file', filename: 'index.html', mimetype: null, fileurl: 'http://f/i', timemodified: 5 },
      { type: 'file', filename: 's.pdf', mimetype: 'application/pdf', fileurl: 'http://f/s', timemodified: 5 }] },
    { id: 90, name: 'Video', modname: 'page', visible: 1, contents: [
      { type: 'file', filename: 'index.html', mimetype: null, fileurl: 'http://f/video', timemodified: 5 }] } ] }]);
  // Page 81's index.html has no video, so it is skipped without an ingest;
  // every other document, the transcript of page 90 included, must carry both fields.
  const sent = [];
  for (const f of ex.files) {
    const r = await processFile({ ...f, action: 'new' });
    if (f.uniqueFilename === 'mod81_transcript.md') { eq(r.out.status, 'skipped'); continue; }
    const want = { cmid: f.moduleId, lms_site: 'http://m', ...(f.hidden ? { hidden_from_students: true } : {}) };
    eq(r.metadata, want, `${f.uniqueFilename}:`);
    sent.push(f.uniqueFilename);
  }
  eq(sent, ['mod70_a.pdf', 'mod72_b.pdf', 'mod81_s.pdf', 'mod90_transcript.md']);
});

acheck('moduleId mancante: fallisce senza chiamare Vektra', async () => {
  const r = await processFile(pfile({ moduleId: undefined }));
  eq(r.out.status, 'failed');
  eq(r.requests.length, 0);
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

console.log('\nProcess Single File (async)');
for (const [label, fn] of asyncChecks) {
  try { await fn(); console.log(`  PASS  ${label}`); pass++; }
  catch (e) { console.log(`  FAIL  ${label}\n        ${e.message}`); fail++; }
}

console.log(`\n${pass} passati, ${fail} falliti\n`);
process.exit(fail ? 1 : 0);
