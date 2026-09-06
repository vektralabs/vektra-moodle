# YouTube Transcript Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Moodle lecture videos searchable in Vektra by ingesting their YouTube transcripts through the existing n8n pipeline.

**Architecture:** `Extract Files` emits each `page` module's `index.html` as an extra candidate alongside the module's existing document attachments. `Process Single File` grows a branch that, for those candidates, downloads the HTML, pulls the embedded YouTube id, fetches the transcript from a `yt-dlp-api` service added to the n8n Compose stack, normalizes it, and pushes it through the multipart upload path that already exists. State keying, dedup and deletion logic are untouched.

**Tech Stack:** n8n 2.17.2 Code nodes (plain JS, builtins `fs,http,https,url` only), `ghcr.io/fvadicamo/yt-dlp-api:weekly`, Vektra `POST /api/v1/ingest`, Moodle Web Services.

**Spec:** `.s2s/specs/20260906-youtube-transcript-ingestion.md`

## Global Constraints

- `NODE_FUNCTION_ALLOW_EXTERNAL` stays empty. No npm packages in Code nodes.
- `NODE_FUNCTION_ALLOW_BUILTIN` stays `fs,http,https,url`. Do not add `child_process`.
- Page modules are identified by `mod.modname === 'page'`, **never** by `mimetype` — a page's `index.html` reports `mimetype: NULL` and `filesize: 0`.
- Collecting a page's `index.html` must **not** short-circuit the module's `SUPPORTED_MIMES` loop. 35 slide PDFs are attached to page modules and must keep being ingested.
- The state file schema stays `state.courseFiles[namespace][fileurl]`. Do not change dedup keys.
- Transcript language is `it`, overridable via `YTDLP_TRANSCRIPT_LANG`.
- Secrets come from the environment. Never commit an API key or a cookie file.
- Commit style: Conventional Commits, per `vektralabs/CLAUDE.md`.

## Ground Truth (dev stack, course 3)

Every task verifies against these measured numbers:

| Fact | Value |
|---|---|
| `page` modules | 71 |
| pages with a video, no PDF | 36 |
| pages with a PDF, no video | 35 |
| pages with both / neither | 0 / 0 |
| transcripts retrievable | 36/36, ~2.5 s each |
| Moodle WS token (dev) | `4dd23fbb88ea7cc4f2bc20ac2827faf0` |

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `n8n/workflows/moodle-ingest.json` | the pipeline | 3 Code nodes modified |
| `n8n/docker-compose.yml` | stack topology | one service added, two env vars passed to n8n |
| `n8n/.env.example` | operator-facing config template | three vars documented |
| `n8n/README.md` | setup and limitations | service setup, cookie gate, ASR caveat |
| `CHANGELOG.md` | release notes | entry under `[Unreleased]` |
| `.s2s/BACKLOG.md` | tracker | FEAT-004 status transitions |

### Editing the workflow JSON

The JS lives inside a JSON string. Hand-editing escaped JSON is how commit
`ad46bb4` ("actually apply ... to workflow JSON") became necessary. **Always**
write the new code to a plain `.js` file and splice it in:

```bash
SP=/tmp/ytfeat && mkdir -p "$SP"          # scratch, never committed
python3 - <<'PY'
import json, pathlib, sys
wf = pathlib.Path('n8n/workflows/moodle-ingest.json')
doc = json.loads(wf.read_text())
code = pathlib.Path('/tmp/ytfeat/NODE.js').read_text()
for n in doc['nodes']:
    if n['name'] == 'NODE NAME':
        n['parameters']['jsCode'] = code
        break
else:
    sys.exit('node not found')
wf.write_text(json.dumps(doc, indent=2, ensure_ascii=False) + '\n')
print('ok')
PY
```

Re-serialising rewrites the whole file. Before the first task, record the
baseline formatting so the diff stays reviewable:

```bash
python3 -c "
import json,pathlib
p=pathlib.Path('n8n/workflows/moodle-ingest.json')
d=json.loads(p.read_text())
p.write_text(json.dumps(d, indent=2, ensure_ascii=False)+'\n')
" && git diff --stat n8n/workflows/moodle-ingest.json
```

If that produces a large diff, commit it **alone** as `chore(n8n): normalise
workflow JSON formatting` before starting Task 1, so later diffs show only real
changes.

---

### Task 1: Extract Files emits page transcript candidates

**Files:**
- Modify: `n8n/workflows/moodle-ingest.json` — node `Extract Files`
- Probe (not committed): `/tmp/ytfeat/t1_probe.mjs`

**Interfaces:**
- Consumes: `core_course_get_contents` output, unchanged.
- Produces: candidate objects in `files[]`. Existing document candidates keep their current shape. New page candidates add `_kind: 'page'` and carry `fileurl` (the `index.html` URL), `timemodified`, `moduleId`, `moduleName`, `uniqueFilename` = `` `mod${mod.id}_transcript.md` ``, `mimetype: 'text/markdown'`. `Dedup & Diff` spreads these through untouched, so `_kind` reaches `Process Single File`.

- [ ] **Step 1: Capture real Moodle output as a fixture**

The dev stack must be running (`cd docker && docker compose up -d`).

```bash
mkdir -p /tmp/ytfeat
cat > /tmp/ytfeat/dump.php <<'PHP'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/externallib.php');
\core\session\manager::set_user(get_admin());
echo json_encode(external_api::clean_returnvalue(
    core_course_external::get_course_contents_returns(),
    core_course_external::get_course_contents(3)));
PHP
docker cp /tmp/ytfeat/dump.php vektra-moodle:/tmp/dump.php
docker exec vektra-moodle php /tmp/dump.php > /tmp/ytfeat/sections.json
python3 -c "import json;print(len(json.load(open('/tmp/ytfeat/sections.json'))),'sections')"
```

Expected: a non-zero section count.

- [ ] **Step 2: Write the failing probe**

This replaces a unit test: the repo has no JS test harness (see Notes), so the
probe runs the node's real code against the real fixture and asserts the
measured ground truth.

```javascript
// /tmp/ytfeat/t1_probe.mjs
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const wf = JSON.parse(readFileSync('n8n/workflows/moodle-ingest.json', 'utf8'));
const code = wf.nodes.find(n => n.name === 'Extract Files').parameters.jsCode;
const sections = JSON.parse(readFileSync('/tmp/ytfeat/sections.json', 'utf8'));

// Minimal n8n shims for the globals the node touches.
const $ = () => ({ item: { json: { id: 3, shortname: 'psicologia-generale' } } });
const $input = { first: () => ({ json: { body: sections } }) };
const run = new Function('$', '$input', `${code}`);
const [{ json }] = run($, $input);

const pages = json.files.filter(f => f._kind === 'page');
const pdfs  = json.files.filter(f => f.mimetype === 'application/pdf');

assert.equal(pages.length, 71, `page candidates: ${pages.length}`);
assert.equal(pdfs.length, 35, `pdf candidates: ${pdfs.length}`);
assert.ok(pages.every(p => p.fileurl.includes('index.html')), 'page fileurl');
assert.ok(pages.every(p => Number.isInteger(p.timemodified) && p.timemodified > 0), 'timemodified');
assert.equal(new Set(json.files.map(f => f.fileurl)).size, json.files.length, 'duplicate fileurl');
console.log(`OK — ${pages.length} page candidates, ${pdfs.length} pdf candidates`);
```

- [ ] **Step 3: Run the probe to verify it fails**

Run: `node /tmp/ytfeat/t1_probe.mjs`
Expected: FAIL — `page candidates: 0`. The current node collects no page modules.

- [ ] **Step 4: Write the new node code**

Write `/tmp/ytfeat/extract_files.js` containing the current node body with the
module loop replaced by the version below. Everything above the loop
(`SUPPORTED_MIMES`, `slugify`, the `_moodleError` guard) is unchanged.

```javascript
for (const section of sections) {
  if (!section.modules) continue;
  for (const mod of section.modules) {
    if (!mod.contents) continue;

    // Page modules carry their HTML body as index.html with a NULL mimetype,
    // so SUPPORTED_MIMES can never match it. Emit it as a transcript
    // candidate; the video id is only discoverable after downloading the HTML.
    // Deliberately NOT followed by `continue`: slide PDFs are attached to page
    // modules alongside index.html, and skipping the loop below would silently
    // stop ingesting all of them.
    if (mod.modname === 'page') {
      const index = mod.contents.find(
        c => c.type === 'file' && c.filename === 'index.html' && c.fileurl
      );
      if (index) {
        files.push({
          _kind: 'page',
          fileurl: index.fileurl,
          filename: `${mod.name}.md`,
          uniqueFilename: `mod${mod.id}_transcript.md`,
          filesize: index.filesize,
          timemodified: index.timemodified,
          mimetype: 'text/markdown',
          moduleId: mod.id,
          moduleName: mod.name,
          sectionName: section.name,
          courseId,
          namespace
        });
      }
    }

    for (const content of mod.contents) {
      if (content.type !== 'file') continue;
      if (!SUPPORTED_MIMES.includes(content.mimetype)) continue;
      files.push({
        fileurl: content.fileurl,
        filename: content.filename,
        uniqueFilename: `mod${mod.id}_${content.filename}`,
        filesize: content.filesize,
        timemodified: content.timemodified,
        mimetype: content.mimetype,
        moduleId: mod.id,
        moduleName: mod.name,
        sectionName: section.name,
        courseId,
        namespace
      });
    }
  }
}
```

Splice it in with the helper from **Editing the workflow JSON**, node name
`Extract Files`.

- [ ] **Step 5: Run the probe to verify it passes**

Run: `node /tmp/ytfeat/t1_probe.mjs`
Expected: `OK — 71 page candidates, 35 pdf candidates`

- [ ] **Step 6: Commit**

```bash
git add n8n/workflows/moodle-ingest.json
git commit -m "feat(n8n): collect page index.html as transcript candidate (FEAT-004)

Page modules report mimetype NULL for their index.html, so the
SUPPORTED_MIMES filter never matched them and no video content ever
reached Vektra. Detect pages by modname and emit the HTML entry as an
extra candidate.

The existing per-content loop deliberately still runs for page modules:
slide PDFs are attached to pages alongside index.html, and returning
early would have silently dropped 35 documents that ingest correctly
today."
```

---

### Task 2: Add the yt-dlp-api service to the n8n stack

**Files:**
- Modify: `n8n/docker-compose.yml`
- Modify: `n8n/.env.example`
- Modify: `n8n/README.md`
- Create: `n8n/cookies/.gitignore`

**Interfaces:**
- Produces: `http://ytdlp-api:8000/api/v1/transcript` reachable from the n8n container; `YTDLP_API_URL` and `YTDLP_API_KEY` present in the n8n container environment. Task 3 consumes both.

- [ ] **Step 1: Add the service**

In `n8n/docker-compose.yml`, add to the `n8n` service `environment:` list, after
`DELETION_SAFETY_THRESHOLD`:

```yaml
      - YTDLP_API_URL=${YTDLP_API_URL:-http://ytdlp-api:8000}
      - YTDLP_API_KEY=${YTDLP_API_KEY}
      - YTDLP_TRANSCRIPT_LANG=${YTDLP_TRANSCRIPT_LANG:-it}
```

Then add the service after the `n8n` service block, before `volumes:`:

```yaml
  ytdlp-api:
    image: ghcr.io/fvadicamo/yt-dlp-api:weekly
    restart: unless-stopped
    environment:
      # The app parses this as a JSON list, not a bare string.
      - 'APP_SECURITY_API_KEYS=["${YTDLP_API_KEY}"]'
      # Startup validation disables the whole YouTube provider when no cookie
      # path is set, even though public-video transcripts need no auth. See
      # n8n/README.md — a non-authenticating placeholder file satisfies it.
      - APP_YOUTUBE_COOKIE_PATH=/app/cookies/youtube.txt
    volumes:
      - ./cookies:/app/cookies:ro
    networks:
      - default
```

- [ ] **Step 2: Keep the cookie directory in git without its contents**

```bash
mkdir -p n8n/cookies
cat > n8n/cookies/.gitignore <<'EOF'
# Cookie files are environment-specific and may carry session data.
*
!.gitignore
EOF
```

- [ ] **Step 3: Document the configuration**

Append to `n8n/.env.example`:

```
# yt-dlp-api (transcript extraction for YouTube course material)
# Any string; it is shared between the n8n workflow and the service.
YTDLP_API_KEY=change-me-to-a-random-string
# Override only if you run the service outside this compose stack.
# YTDLP_API_URL=http://ytdlp-api:8000
# Caption language requested from YouTube.
YTDLP_TRANSCRIPT_LANG=it
```

- [ ] **Step 4: Document setup and the cookie gate in the README**

Add a section to `n8n/README.md`:

````markdown
## Transcript extraction (YouTube course material)

Course videos embedded in Moodle pages are ingested as transcripts by the
`ytdlp-api` service in this stack ([yt-dlp-api](https://github.com/fvadicamo/yt-dlp-api),
MIT). The image is pre-built; there is nothing to compile.

### Required: a placeholder cookie file

The service refuses to start its YouTube provider unless a cookie path is
configured, and requests then fail with
`INVALID_URL: No provider available for URL`. Transcripts of public videos need
no authentication, so a placeholder satisfying the format check is enough:

```bash
mkdir -p n8n/cookies
printf '# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t%s\tPREF\tf1=50000000\n' \
  "$(( $(date +%s) + 31536000 ))" > n8n/cookies/youtube.txt
```

Replace it with a real exported cookie file only if you also use the service for
downloads, which do require authentication.

### Limitation: transcripts are machine-generated

Lecture videos carry YouTube's automatic captions, not human-authored
subtitles. Proper nouns are frequently wrong — in the Psicologia generale
corpus the neuropsychology case *Phineas Gage* is transcribed as *"Finess
Cage"*. A student searching the correct spelling will not retrieve that
passage. Each ingested document states its provenance in a header so the
origin of a citation is visible.
````

- [ ] **Step 5: Bring the stack up and verify health**

```bash
cd n8n
grep -q '^YTDLP_API_KEY=' .env || echo "YTDLP_API_KEY=local-dev-key" >> .env
mkdir -p cookies
printf '# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t%s\tPREF\tf1=50000000\n' \
  "$(( $(date +%s) + 31536000 ))" > cookies/youtube.txt
docker compose up -d ytdlp-api
sleep 15
docker compose exec -T ytdlp-api curl -sf http://localhost:8000/health \
  | python3 -c "import sys,json;d=json.load(sys.stdin);print(d['status']);[print(' ',k,v['status']) for k,v in d['components'].items()]"
```

Expected: `healthy`, with every component healthy including `cookie`.

- [ ] **Step 6: Verify n8n can reach the service**

```bash
cd n8n
docker compose up -d n8n
docker compose exec -T n8n sh -lc '
  wget -q -O - --header="X-API-Key: $YTDLP_API_KEY" \
  "$YTDLP_API_URL/api/v1/transcript?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3D7Fp_u-ylGPE&lang=it&source=auto&fmt=text" \
  | head -c 200'
```

Expected: Italian transcript text beginning `Benissimo, benvenuti a questa lezione`.
A `No provider available` error means the cookie file is missing or unreadable.

- [ ] **Step 7: Commit**

```bash
git add n8n/docker-compose.yml n8n/.env.example n8n/README.md n8n/cookies/.gitignore
git commit -m "feat(n8n): add yt-dlp-api service for transcript extraction (FEAT-004)

Pinned to the :weekly tag so yt-dlp tracking of YouTube's changes is
inherited rather than maintained here. NODE_FUNCTION_ALLOW_EXTERNAL
stays empty: the workflow reaches the service over HTTP like it already
does for Moodle and Vektra.

Documents the startup gate that disables the YouTube provider when no
cookie path is configured, which is misleading for transcript-only
deployments since public-video transcripts need no authentication."
```

---

### Task 3: Process Single File resolves transcripts for page candidates

**Files:**
- Modify: `n8n/workflows/moodle-ingest.json` — node `Process Single File`

**Interfaces:**
- Consumes: candidates from Task 1 carrying `_kind === 'page'`; the service from Task 2 via `$env.YTDLP_API_URL`, `$env.YTDLP_API_KEY`, `$env.YTDLP_TRANSCRIPT_LANG`.
- Produces: result objects with `status` of `completed`, `skipped` or `failed`. `skipped` is new and is consumed by Task 4.

- [ ] **Step 1: Insert the branch**

In `/tmp/ytfeat/process_single_file.js`, keep Steps 1 and 2 (delete-old-version,
Moodle download) exactly as they are — pages use the same download path. Insert
the following **between** the existing JSON-content-type guard and Step 3, and
change Step 3 to upload `uploadBuffer` / `uploadName` / `uploadMime` instead of
`dlResp.body` / `uniqueFilename` / `file.mimetype`.

```javascript
  // Step 2b: page modules carry a YouTube embed rather than a document.
  // Resolve the transcript and upload that instead of the HTML itself.
  let uploadBuffer = dlResp.body;
  let uploadName = uniqueFilename;
  let uploadMime = file.mimetype;

  if (file._kind === 'page') {
    const html = dlResp.body.toString('utf8');
    const match = html.match(
      /(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{11})/
    );

    // Roughly half the page modules are slide pages with no video. Their
    // attached PDFs are ingested separately; the page itself is not content.
    if (!match) {
      return [{ json: {
        file_name: uniqueFilename, namespace, courseId,
        action: file.action, status: 'skipped', document_id: null,
        chunk_count: 0, error: null
      }}];
    }

    const videoId = match[1];
    const watchUrl = `https://www.youtube.com/watch?v=${videoId}`;
    const lang = $env.YTDLP_TRANSCRIPT_LANG || 'it';
    const trResp = await httpReq(
      `${$env.YTDLP_API_URL}/api/v1/transcript`
        + `?url=${encodeURIComponent(watchUrl)}`
        + `&lang=${encodeURIComponent(lang)}&source=auto&fmt=text`,
      { headers: { 'X-API-Key': $env.YTDLP_API_KEY } }
    );

    if (trResp.statusCode < 200 || trResp.statusCode >= 300) {
      let detail = `HTTP ${trResp.statusCode}`;
      try {
        const parsed = JSON.parse(trResp.body.toString());
        detail = parsed.detail?.error_code || parsed.error_code || detail;
      } catch (_) { /* non-JSON error body; keep the status code */ }
      return [{ json: {
        file_name: uniqueFilename, namespace, courseId,
        action: file.action, status: 'failed', document_id: null,
        chunk_count: 0,
        error: `Transcript fetch failed for ${videoId}: ${detail}`
      }}];
    }

    // fmt=text inherits the caption file's hard wraps (~37 chars, mid
    // sentence). Collapsing them back into flowing prose keeps chunk
    // boundaries on sentences instead of on subtitle cues.
    const transcript = trResp.body.toString('utf8')
      .replace(/\r\n/g, '\n')
      .replace(/[ \t]*\n[ \t]*/g, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();

    if (!transcript) {
      return [{ json: {
        file_name: uniqueFilename, namespace, courseId,
        action: file.action, status: 'failed', document_id: null,
        chunk_count: 0, error: `Empty transcript for ${videoId}`
      }}];
    }

    const retrieved = new Date().toISOString().slice(0, 10);
    const markdown = `# ${file.moduleName}\n\n`
      + `Source: ${watchUrl}\n`
      + `Transcript: auto-generated captions (${lang}), retrieved ${retrieved}\n\n`
      + `${transcript}\n`;

    uploadBuffer = Buffer.from(markdown, 'utf8');
    uploadMime = 'text/markdown';
  }
```

Step 3 becomes:

```javascript
  const boundary = '----n8nBoundary' + Date.now();
  const header = `--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="${uploadName}"\r\nContent-Type: ${uploadMime}\r\n\r\n`;
  const footer = `\r\n--${boundary}--\r\n`;
  const multipartBody = Buffer.concat([Buffer.from(header), uploadBuffer, Buffer.from(footer)]);
```

Splice in with the helper, node name `Process Single File`.

- [ ] **Step 2: Verify the pure logic against all 71 real pages**

```javascript
// /tmp/ytfeat/t3_probe.mjs
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const wf = JSON.parse(readFileSync('n8n/workflows/moodle-ingest.json', 'utf8'));
const code = wf.nodes.find(n => n.name === 'Process Single File').parameters.jsCode;

// The regex and the normalizer are the two pure pieces worth isolating.
const RE = /(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{11})/;
assert.ok(code.includes('youtube(?:-nocookie)?'), 'regex present in node');
assert.ok(code.includes("status: 'skipped'"), 'skipped branch present');
assert.ok(code.includes('uploadBuffer'), 'upload indirection present');

const sections = JSON.parse(readFileSync('/tmp/ytfeat/sections.json', 'utf8'));
const token = process.env.MOODLE_WS_TOKEN;
assert.ok(token, 'set MOODLE_WS_TOKEN');

let withVideo = 0, without = 0;
for (const s of sections) {
  for (const m of s.modules ?? []) {
    if (m.modname !== 'page') continue;
    const idx = (m.contents ?? []).find(c => c.filename === 'index.html');
    if (!idx) continue;
    const sep = idx.fileurl.includes('?') ? '&' : '?';
    const html = await (await fetch(`${idx.fileurl}${sep}token=${token}`)).text();
    RE.test(html) ? withVideo++ : without++;
  }
}
assert.equal(withVideo, 36, `pages with video: ${withVideo}`);
assert.equal(without, 35, `pages without video: ${without}`);
console.log(`OK — ${withVideo} with video, ${without} without`);
```

Run: `MOODLE_WS_TOKEN=4dd23fbb88ea7cc4f2bc20ac2827faf0 node /tmp/ytfeat/t3_probe.mjs`
Expected: `OK — 36 with video, 35 without`

- [ ] **Step 3: Verify the normalizer output shape**

```bash
curl -s -H "X-API-Key: ${YTDLP_API_KEY:-local-dev-key}" \
  "http://localhost:8000/api/v1/transcript?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3D7Fp_u-ylGPE&lang=it&source=auto&fmt=text" \
  > /tmp/ytfeat/raw.txt
node -e '
const raw = require("fs").readFileSync("/tmp/ytfeat/raw.txt","utf8");
const out = raw.replace(/\r\n/g,"\n").replace(/[ \t]*\n[ \t]*/g," ").replace(/\s{2,}/g," ").trim();
console.log("newlines:", (out.match(/\n/g)||[]).length);
console.log("words:", out.split(/\s+/).length);
console.log(out.slice(0,120));
'
```

Expected: `newlines: 0`, roughly 1800 words, prose without mid-sentence breaks.

- [ ] **Step 4: Commit**

```bash
git add n8n/workflows/moodle-ingest.json
git commit -m "feat(n8n): ingest YouTube transcripts for page modules (FEAT-004)

Page candidates resolve to a transcript instead of uploading the HTML:
extract the embedded video id, fetch captions from yt-dlp-api, collapse
the caption file's hard wraps back into prose, and push the result
through the existing multipart ingest path as Markdown.

Pages with no embed report 'skipped' rather than failing — roughly half
the page modules are slide pages whose PDFs ingest separately. A
transcript fetch failure fails that page alone and lets the course run
continue, matching the existing Moodle-download failure shape."
```

---

### Task 4: Report skipped pages distinctly in the summary

**Files:**
- Modify: `n8n/workflows/moodle-ingest.json` — node `Ingestion Summary`

**Interfaces:**
- Consumes: `status: 'skipped'` results from Task 3.
- Produces: `totals.skipped` in the summary payload.

- [ ] **Step 1: Separate skipped from unchanged**

`Ingestion Summary` currently folds `skipped` into `unchanged`:

```javascript
    else if (d.action === 'unchanged' || d.status === 'skipped') totalUnchanged++;
```

With 35 video-less pages per run that hides a real signal behind a count that
means "nothing to do". Replace with:

```javascript
    else if (d.status === 'skipped') totalSkipped++;
    else if (d.action === 'unchanged') totalUnchanged++;
```

Declare `let totalSkipped = 0;` alongside the other counters, and update both
the summary string and the totals object:

```javascript
const summary = `Sync complete: ${totalNew} new, ${totalUpdated} updated, ${totalRemoved} removed, ${totalUnchanged} unchanged, ${totalSkipped} skipped, ${totalFailed} failed`;
```

```javascript
    totals: { new: totalNew, updated: totalUpdated, removed: totalRemoved, unchanged: totalUnchanged, skipped: totalSkipped, failed: totalFailed },
```

- [ ] **Step 2: Verify the node parses and the counter is wired**

```bash
node -e '
const wf = JSON.parse(require("fs").readFileSync("n8n/workflows/moodle-ingest.json","utf8"));
const c = wf.nodes.find(n => n.name === "Ingestion Summary").parameters.jsCode;
for (const s of ["let totalSkipped","skipped: totalSkipped","${totalSkipped} skipped"]) {
  if (!c.includes(s)) { console.error("MISSING:", s); process.exit(1); }
}
if (/action === .unchanged. \|\| d\.status === .skipped./.test(c)) {
  console.error("old conflated branch still present"); process.exit(1);
}
new Function(c); console.log("OK");
'
```

Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add n8n/workflows/moodle-ingest.json
git commit -m "feat(n8n): count skipped pages separately in the summary (FEAT-004)

Skipped was folded into unchanged, which reads as 'nothing to do'. With
roughly half of all page modules skipped on every run, the two need to
be distinguishable to tell a healthy run from a broken extractor."
```

---

### Task 5: End-to-end verification and release notes

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `.s2s/BACKLOG.md`

- [ ] **Step 1: Record the baseline**

```bash
cd n8n
docker compose exec -T n8n sh -lc \
  'cat ${STATE_FILE_PATH:-/home/node/.n8n/moodle-ingest-state.json} 2>/dev/null || echo "{}"' \
  > /tmp/ytfeat/state_before.json
python3 -c "
import json;d=json.load(open('/tmp/ytfeat/state_before.json'))
print('stored files:', sum(len(v) for v in d.get('courseFiles',{}).values()))"
```

- [ ] **Step 2: Run the workflow and assert the acceptance numbers**

Trigger the workflow from the n8n UI (`http://localhost:5678`) or wait for
`INGEST_CRON`. Then read the summary from the execution output.

Expected totals for a first run on course 3:
- `new`: 36 transcripts + 35 PDFs, minus anything already in state
- `skipped`: **35** — the slide pages with no embed
- `failed`: **0**

Assert no page produced two documents:

```bash
docker compose exec -T n8n sh -lc \
  'cat ${STATE_FILE_PATH:-/home/node/.n8n/moodle-ingest-state.json}' \
  > /tmp/ytfeat/state_after.json
python3 -c "
import json, collections
d = json.load(open('/tmp/ytfeat/state_after.json'))
for ns, files in d.get('courseFiles', {}).items():
    tr = [k for k in files if 'index.html' in k]
    print(f'{ns}: {len(tr)} transcripts, {len(files)-len(tr)} documents')
    assert len(tr) == len(set(tr)), 'duplicate transcript keys'
"
```

Expected: `36 transcripts, 35 documents`.

- [ ] **Step 3: Verify idempotence**

Run the workflow a second time with no content change.

Expected: `new: 0`, `updated: 0`, `unchanged: 71`, `skipped: 35`, `failed: 0`.

- [ ] **Step 4: Verify update handling**

Edit one video page in Moodle (change its name or content), re-run.

Expected: `updated: 1`, and the previous document id deleted from Vektra before
the new upload. Confirm in the execution log that Step 1 of
`Process Single File` ran a `DELETE /api/v1/documents/batch`.

- [ ] **Step 5: Verify graceful degradation**

```bash
cd n8n && docker compose stop ytdlp-api
```

Re-run the workflow.

Expected: the run completes; the 36 video pages report `failed` with an error
starting `Transcript fetch failed for`; the 35 PDFs still ingest. Then:

```bash
docker compose start ytdlp-api
```

- [ ] **Step 6: Confirm the sandbox was not widened**

```bash
grep -n 'NODE_FUNCTION_ALLOW' n8n/docker-compose.yml
```

Expected: `NODE_FUNCTION_ALLOW_BUILTIN=fs,http,https,url` and
`NODE_FUNCTION_ALLOW_EXTERNAL=` with nothing after the `=`.

- [ ] **Step 7: Spot-check one ingested document**

Query Vektra for one transcript document and confirm it opens with the
provenance header and contains no mid-sentence line breaks.

- [ ] **Step 8: Write the changelog entry**

Under `## [Unreleased]` in `CHANGELOG.md`:

```markdown
### Added

- **n8n — YouTube transcript ingestion** (FEAT-004): lecture videos embedded
  in Moodle page modules are now ingested as transcripts. The workflow
  collects each page's `index.html`, extracts the embedded YouTube id, and
  fetches captions from a new `ytdlp-api` service in the n8n stack
  ([yt-dlp-api](https://github.com/fvadicamo/yt-dlp-api), pinned to `:weekly`).
  Transcripts are normalized and ingested as Markdown with a provenance
  header. Pages without an embed are reported `skipped`, which the ingestion
  summary now counts separately from `unchanged`.

  Transcripts are YouTube's automatic captions, not human-authored subtitles:
  proper nouns are frequently mis-transcribed. See `n8n/README.md`.

### Fixed

- **n8n — page modules were invisible to ingestion**: a page's `index.html`
  reports `mimetype: NULL`, so the `SUPPORTED_MIMES` filter silently skipped
  every page module. Detection now keys on `modname`.
```

- [ ] **Step 9: Move FEAT-004 to Completed**

In `.s2s/BACKLOG.md`, move the FEAT-004 block from `## Planned` to
`## Completed`, set `**Status**: completed`, add `| **Completed**: <today>`,
and tick every acceptance criterion that the runs above confirmed. Restore the
`<!-- No planned items. -->` placeholder under `## Planned` if nothing else
remains there.

- [ ] **Step 10: Commit**

```bash
git add CHANGELOG.md .s2s/BACKLOG.md
git commit -m "docs(changelog,backlog): record YouTube transcript ingestion (FEAT-004)"
```

- [ ] **Step 11: Open the pull request**

Base the PR on `develop`, following the repository's existing merge pattern.
Summarise: what was invisible before, the mimetype finding, the PDF-attachment
constraint, the measured run results, and the ASR limitation.

---

## Notes

**No JS test harness exists.** The repository has no `package.json`, no test
runner, and CI lints PHP only. The workflow's embedded JS has produced roughly
ten tracked bugs (BUG-004, 008, 009, 011, 013, 014, 016, 017), so this is a real
gap — but closing it means choosing and introducing a test framework, which is a
decision for the maintainers rather than something to smuggle into a feature
branch. The probes in Tasks 1 and 3 give equivalent confidence for this change
by running the real node code against real course data. **Recommendation:** file
a DEBT item for a workflow test harness.

**Do not commit the probes.** They live in `/tmp/ytfeat/` and depend on the dev
stack and a live WS token.

**The dev-stack WS token is not a secret** in the sense that matters here — it
belongs to a local throwaway Moodle. Do not reuse the pattern for real tokens.
