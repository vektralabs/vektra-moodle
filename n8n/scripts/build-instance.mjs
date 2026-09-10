#!/usr/bin/env node
// Emits the workflow JSON for one instance, ready for `n8n import:workflow`.
//
// There is one template in this repo and no per-instance copies, because two
// copies of the same pipeline drift: that is how mooc.unical.it ended up months
// behind main, missing three features, and hardcoding a state path that would
// have made both instances delete each other's documents.
//
//   node n8n/scripts/build-instance.mjs mooc > /tmp/mooc.json
//
// Only the Config node differs between instances, and only in which environment
// variables it reads. No secret is ever written into the output.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const name = process.argv[2];
if (!name) {
  console.error('usage: build-instance.mjs <instance>   (see n8n/instances/)');
  process.exit(1);
}

const inst = JSON.parse(readFileSync(join(root, 'n8n/instances', `${name}.json`), 'utf8'));
const wf = JSON.parse(readFileSync(join(root, 'n8n/workflows/moodle-ingest.json'), 'utf8'));

const cfg = wf.nodes.find((n) => n.name === 'Config');
if (!cfg) throw new Error('template has no Config node — nothing to parameterise');

// Instance identity only. INGEST_OPT_OUT_TAG stays unprefixed on purpose: the
// opt-out tag is a convention taught to teachers, and it should read the same
// across every Moodle of the same university.
const prefix = inst.envPrefix ?? '';
for (const v of ['MOODLE_URL', 'MOODLE_WS_TOKEN', 'STATE_FILE_PATH']) {
  const from = `$env.${v}`;
  const to = `$env.${prefix}${v}`;
  if (!cfg.parameters.jsCode.includes(from)) throw new Error(`Config node does not read ${from}`);
  cfg.parameters.jsCode = cfg.parameters.jsCode.split(from).join(to);
}

wf.id = inst.id;
wf.name = inst.name;
process.stdout.write(JSON.stringify(wf, null, 2) + '\n');
