# vektra-moodle Backlog

**Updated**: 2026-04-30 (post-v0.5.0 release cleanup)
**Format**: Single markdown file for tracking work items

---

## ID Conventions

| Prefix | Category | Example |
|--------|----------|---------|
| FEAT | Features | FEAT-001 |
| BUG | Bug fixes | BUG-001 |
| TECH | Technical tasks | TECH-001 |
| DEBT | Technical debt | DEBT-001 |

**Status values**: `draft` | `planned` | `in_progress` | `blocked` | `completed`

---

## Planned

### DEBT-010: Only the first course with files is ingested in a multi-course run

**Status**: planned | **Priority**: high | **Created**: 2026-09-10
**Origin**: surfaced while testing FEAT-008, which finally put two courses with
files through the pipeline in one run

**Context**: with two courses in scope, `Dedup & Diff` correctly reported work
for both — `psicologia-generale` 35 new, `no-vektra-test` 1 new — but
`Process Single File` ran 36 times and every one of them belonged to the first
course. The second course's file was never processed. `Merge Course Results`
then emitted two items, the second containing the first course's 35 results
again, so `Ingestion Summary` reported 70 skipped instead of 35.

**Not caused by FEAT-008**: the same run on the pre-change workflow from
`develop` produced identical numbers — same 36 executions, same missing file,
same doubled count. The scoping change only decides which courses enter the
loop.

**Likely cause**: `Loop Files` is a `splitInBatches` node that keeps its state
for the whole execution. Once its loop has completed for the first course it
reports done immediately for the next one, so the second course's items are
never iterated and its `Merge Course Results` collects the previous course's
output. `splitInBatches` has a reset option for exactly this.

**What is not yet known, and matters**: whether this reproduces under the
schedule trigger or only under `n8n execute` on the CLI, which is how it was
observed. The evidence points at the CLI being a factor rather than the cause:
the server holds documents for `abilitazione-insegnamento` and
`storia-ambiente` alongside `psicologia-generale`, so more than one course has
been ingested there at some point. That must be established before the fix, or
the fix will be aimed at the wrong thing.

**Why it matters**: if it does reproduce on a schedule, then on any Moodle with
more than one scoped course only one of them is ever indexed, silently, and the
summary's numbers hide it by double-counting. FEAT-008 makes multi-course runs
the normal case rather than the exception.

**Acceptance criteria**:
- [ ] Reproduced (or ruled out) under the schedule trigger, not only the CLI
- [ ] Every scoped course's files are processed in a single run
- [ ] `Ingestion Summary` counts each file once
- [ ] A probe covers a two-course run where both courses have files to ingest

---

### DEBT-003: Credentials travel in cleartext between the ingestion containers

**Status**: planned | **Priority**: medium | **Created**: 2026-09-08
**Raised by**: CodeRabbit on PR #29 (comments 3956019179, 3956019205), CWE-319

**Context**: Every credential the ingestion pipeline carries moves over plain
HTTP on the Compose networks:

| Hop | Credential | Transport |
|---|---|---|
| n8n -> Moodle | `MOODLE_WS_TOKEN` | query string, HTTP |
| n8n -> Vektra | `VEKTRA_API_KEY` | `Authorization` header, HTTP |
| n8n -> ytdlp-api | `YTDLP_API_KEY` | `X-API-Key` header, HTTP |

The third was added by FEAT-004 and is what surfaced the finding, but it is the
least valuable of the three: anyone able to observe that bridge already holds
the Moodle web-service token and the Vektra ingest key. Encrypting one hop
would not reduce the exposure, only make the stack inconsistent — which is why
FEAT-004 declined to do it in isolation rather than because the finding is wrong.

**This item is not a fix.** It records an accepted risk. The risk is bounded by
the networks being private to the Compose project, and it stands until the work
below is done.

**Scope of an actual fix**:
- TLS termination in front of `ytdlp-api`, natively or via a reverse proxy
- A local CA or self-signed certificates that `httpReq` in the workflow trusts
- The same treatment for the Moodle and Vektra hops, or the exposure is unchanged
- Roughly half a day, touching three services; deliberately out of scope for a
  feature branch that only added one more consumer of an existing pattern

**Acceptance criteria**:
- [ ] All three hops use TLS with certificate validation, or an equivalent authenticated transport boundary
- [ ] No credential is observable to a process that can read the Compose bridge
- [ ] `n8n/README.md` documents the certificate setup

---

### BUG-018: dev-stack Moodle wwwroot points at localhost, breaking n8n calls

**Status**: completed | **Priority**: high | **Created**: 2026-09-07 | **Completed**: 2026-09-08

**Context**: Every workflow run died at the first HTTP node, `Get Courses`, in
under a tenth of a second. Originally logged as a Docker networking fault
because `wget` from the n8n container reported `Connection refused` while a
throwaway container on the same network appeared to succeed.

**That diagnosis was wrong.** The network was fine throughout: `nc -z` from the
n8n container reported port 80 open on Moodle at the same moment `wget` to the
same address reported `Connection refused`. Verbose `wget` showed why:

```
Connecting to 172.21.0.3 (172.21.0.3:80)
Connecting to localhost:10180 ([::1]:10180)
wget: can't connect to remote host: Connection refused
```

Moodle answered, then redirected to its `$CFG->wwwroot`, which the dev stack
defaults to `http://localhost:10180` for browser convenience. Inside the n8n
container `localhost` is n8n itself, where nothing listens on 10180. The
throwaway-container test had only ever checked the `303` status without
following the redirect, which is what made the fault look container-specific.

**Resolution**: set `MOODLE_URL=http://vektra-moodle` in `docker/.env` and
recreate the Moodle container, so `wwwroot` matches the hostname n8n calls.
This is the configuration `docker/docker-compose.yml:29-32` already documents;
it had simply never been applied to this environment. It is not a code defect
and affects no deployment where the two stacks were brought up together.

**Consequence for browser access**: with `wwwroot` set to the Docker hostname,
opening `http://localhost:10180` from the host redirects to `http://vektra-moodle`
and fails until `127.0.0.1 vektra-moodle` is added to the host's `/etc/hosts`,
as the same compose comment states.

**Acceptance criteria**:
- [x] Root cause identified
- [x] `wget "$MOODLE_URL/login/index.php"` succeeds from inside the n8n container
- [x] `core_course_get_courses` returns JSON to n8n
- [x] A full workflow run reaches `Extract Files` and completes

---

### DEBT-001: Workflow JS has no test harness

**Status**: planned | **Priority**: medium | **Created**: 2026-09-07

**Context**: `n8n/workflows/moodle-ingest.json` carries several hundred lines of
JavaScript inside JSON strings, and CI lints PHP only. That code has produced
roughly ten tracked bugs (BUG-004, 008, 009, 011, 013, 014, 016, 017). FEAT-004
was verified with throwaway probes that executed the real node code against real
course data, which worked well but lived in `/tmp` and died with the session.

Choosing and introducing a test framework is a maintainer decision, which is why
FEAT-004 did not do it on a feature branch.

**Acceptance criteria**:
- [ ] Node code extractable and unit-testable without a running n8n
- [ ] Tests run in CI on pull requests
- [ ] The FEAT-004 probes are ported into it

---

### DEBT-002: Ten action/status combinations are counted in no ingestion total

**Status**: planned | **Priority**: low | **Created**: 2026-09-07

**Context**: Found while reviewing FEAT-004; predates it. Executing
`Ingestion Summary` over the full cross product of every `action` and `status`
the pipeline can emit shows 10 combinations that match none of its six counting
branches: `new/exists`, `new/alias`, `new/unchanged`, `updated/exists`,
`updated/alias`, `updated/unchanged`, `removed/new`, `removed/exists`,
`removed/alias`, `removed/unchanged`.

The reachable ones are `new/exists` and `new/alias`: `Process Single File` keeps
Vektra's raw status when the response carries no `document_id`. Such results
still appear in `details` but in no total, so `new + updated + removed +
unchanged + skipped + failed` can silently be less than the number of files
processed.

**Acceptance criteria**:
- [ ] Every emitted combination increments exactly one total, or a explicit `other` bucket exists
- [ ] A regression check covers the cross product

---

---

## In Progress

<!-- Move items here when work begins -->

---

## Completed

### FEAT-008: Index only the courses that carry the Vektra block

**Status**: completed | **Priority**: high | **Created**: 2026-09-10 | **Completed**: 2026-09-10
**Branch**: `feat/ingest-scope-by-block`
**Origin**: the pipeline indexed every course; on `mooc.unical.it` that is ~50
courses, the whole university

**Context**: `Filter Courses` excluded only the site course, so every course on
the installation was ingested. The wanted model is self-service: a teacher adds
the Vektra block to a course and its material is indexed on the next run; they
remove the block and it comes out. The namespace list on the engine side is not
a usable signal — ingest and token creation both create namespaces — so the
signal has to come from Moodle, and the block is it.

**Implementation**: two nodes between `Filter Courses` and `Loop Courses`.
`Get Course Blocks` calls `core_block_get_course_blocks` once per course;
`Scope Courses` decides what happens to each. A course with its own block passes
through unchanged. A course with no block that is not in the index is dropped
and costs nothing further. A course with no block that **is** in the index is
marked `_outOfScope` and travels the normal deletion path. A course whose lookup
failed is left out of the run entirely.

Two existing nodes needed one line each: `Extract Files` returns an empty file
list for an `_outOfScope` course, and `Dedup & Diff` exempts it from the
empty-course safety net, the same way the opt-out tag is exempt.

**The finding that shaped it**: filtering a course out does not remove it from
the index — it freezes it. `Dedup & Diff` runs per course, so a course that
never reaches `Loop Courses` is never compared against the state and never
produces deletions. Its stale index would keep answering students forever.
"Remove the block and it comes out" therefore had to be written, not assumed.

**Two ways the signal can lie, both handled**:
- A block placed on the site or a category with *show in subcontexts* is
  reported under every course beneath it. Measured: a block inserted in the
  system context made a course with no block of its own report `vektra`. It is
  distinguishable because the inherited instance id is the same under every
  course, while a per-course block's id is unique to its course.
- Moodle answers a web-service exception with HTTP 200 and an `exception` body.
  Read as "no block", a failing token would prune every course at once. This is
  not hypothetical: the `mooc.unical.it` token belongs to a person's account
  (see DEBT-009), so all lookups can start failing together.

**Verified**: 27 logic probes (`Scope Courses`, `Dedup & Diff`, `Extract Files`,
`Ingestion Summary`), plus three runs on the live local stack:
- two courses, one with the block — only that one entered the loop, the other
  was dropped, nothing was deleted
- the same two with the block added to the second — both entered scope
- the block removed from the second while its document was in the index — the
  document was deleted from Vektra (`deleted_at` set, `deletion_reason
  user_request`), its state entry dropped, the empty-course net did not fire,
  and the summary reported `1 course(s) left the index`

The response contract was confirmed on Moodle 5.1.3 locally, on mooc2 (5.1.4) by
the ops session, and for `mooc.unical.it` (4.3.3) by diffing
`blocks/classes/external.php` between `MOODLE_403_STABLE` and 5.1 — the file is
identical, so the `name` field carries the same value there.

**Deployment prerequisite**: `core_block_get_course_blocks` must be added to the
`n8n Ingestion` web service on each instance. The service id differs between
installations (2 on one, 3 on the other).

---

### FEAT-007: One workflow template for several Moodle instances

**Status**: completed | **Priority**: high | **Created**: 2026-09-10 | **Completed**: 2026-09-10
**Branch**: `feat/ingest-instance-config`
**Origin**: deploy of FEAT-005/006 surfaced a second, drifted workflow on the server

**Context**: `mooc.unical.it` runs the same pipeline as a hand-maintained copy.
It had fallen months behind — missing FEAT-004, FEAT-005 and FEAT-006, though it
carried every bugfix — and hardcoded its web-service token and its state path.
Copying the template over it by hand would have delivered the features and set up
the next divergence.

**Implementation**: a `Config` node at the head holds instance identity, read
from the environment; six nodes read from it instead of `$env` or literals.
Instances are one small file each under `n8n/instances/`, and
`n8n/scripts/build-instance.mjs` emits the JSON to import. There are no
per-instance JSONs in the repository, so they cannot go stale.

**The finding that shaped it**: the two workflows differ in four configuration
points, not the three that were visible. The fourth is the state file path, and
it is the dangerous one. Importing the template into the second instance would
have pointed both at one state file: each run classifies the other instance's
documents as removed, deletes them, and re-ingests them next run. The
empty-course guard cannot catch it — it only fires on an empty file list, and
neither list is ever empty. `Config` therefore refuses to start without a state
path rather than defaulting.

**Verified**:
- [x] Generated main and mooc differ in exactly one node, `Config`; connections identical
- [x] Neither output contains a token, a hardcoded host, or a hardcoded state path
- [x] mooc reads `MOOC_`-prefixed variables and none of the unprefixed ones, so the two cannot collide
- [x] `INGEST_OPT_OUT_TAG` stays unprefixed by design — a convention taught to teachers
- [x] `Config` fails with a named error for each of the three required variables
- [x] The full probe harness passes against the refactored template: no behavioural change

**Not verified here**: the generated mooc workflow has never run. Deployment and
the first run belong to whoever has VM access. That first run is heavy — mooc has
never collected page modules, so it downloads every video transcript from
scratch.

---

### FEAT-006: Propagate module visibility to the ingest API

**Status**: completed | **Priority**: medium | **Created**: 2026-09-09 | **Completed**: 2026-09-09
**Branch**: `feat/ingest-visibility-flag` (stacked on `feat/ingest-opt-out-tag`)
**Origin**: meeting point 1 — hidden material should be usable by the assistant without its source being shown
**Contract**: fixed by vektra-stack (Task 2a) — `hidden_from_students` boolean, inside the `metadata` form field as flat JSON, raised on `visible === 0`

**Implementation**: `Extract Files` carries `hidden: mod.visible === 0` on every
candidate; `Dedup & Diff` puts it in the change signature beside `timemodified`;
`Process Single File` adds a `metadata` part to the multipart when the module is
hidden and records the visibility in the state file.

**Why the signature had to change**: hiding a module leaves every file
byte-identical, so `timemodified` alone reports the flip as unchanged. The
document would never be re-ingested and its flag would stay stale — the source
would keep being cited. Re-ingesting without deleting first does not help
either: measured against the running backend, an unchanged content hash returns
`status: exists` with the same `document_id` and the request's metadata ignored.
The `updated` branch deletes the old document before uploading, which is what
makes a flip take effect.

**Verified**:
- [x] Hiding a module classifies it `updated` and carries `old_document_id`
- [x] Un-hiding does the same in reverse
- [x] No change leaves it `unchanged`
- [x] Legacy state carrying no `hidden` field behaves correctly in both
      directions: a file whose module is visible stays `unchanged`, so upgrading
      triggers no spurious re-ingest, while a file whose module is currently
      hidden reads as a flip (stored false vs reported true), is classified
      `updated`, and migrates through the re-ingest path — acquiring the flag it
      never had. The second case is the intended migration, not a side effect
- [x] On the wire, a hidden module's request carries
      `{"hidden_from_students":true}` in a `metadata` form part, and a visible
      module's request carries no metadata part at all — captured by pointing the
      workflow at an echo server for one run
- [x] Metadata key matches the contract's `^[a-z][a-z0-9_]{0,63}$`

**Blocked downstream, not here** — the blocking work is vektra-stack PR #139,
which consumes and validates the field. It is neither merged nor deployed, so
every measurement above was taken against a pre-#139 stack. A well-formed
request stores no `hidden_from_students` anywhere, and the contract's validation
rules are absent: nested objects, non-conforming keys and even malformed JSON in
`metadata` all return HTTP 200. End-to-end confirmation that the flag reaches
Qdrant needs their half; the workflow side is complete and measured.

**E2E acceptance probe, for when #139 is live** (agreed with the coordinating
session and on their deploy checklist). Against the deployed stack all three
must return 422, where today they return 200:

- `metadata={"Hidden_From_Students": true}` — key outside `^[a-z][a-z0-9_]{0,63}$`
- `metadata={"a":{"b":1}}` — nested rather than flat
- `metadata=non-json` — malformed

and a hidden module's Qdrant points must carry `hidden_from_students` in their
payload, where today they hold only chunker-generated keys. Nothing on the
workflow side changes for this to start passing.

---

### FEAT-005: Teacher opt-out from the index via a title tag

**Status**: completed | **Priority**: medium | **Created**: 2026-09-08 | **Completed**: 2026-09-08
**Branch**: `feat/ingest-opt-out-tag`
**Origin**: meeting point 2 — teachers need a way to keep specific material out of the assistant

**Context**: A teacher had no way to exclude a module from the AI index short of
unpublishing it in Moodle. A tag in the module title now does it:
`INGEST_OPT_OUT_TAG`, default `[no-ai]`, matched case-insensitively anywhere in
the title. Tagging excludes everything the module contributes, documents and
video transcript alike, because the teacher tags the module rather than a file.

**Implementation**: a single filter in `Extract Files`, placed before the module
produces any candidate. Deletion needed no new code: `Dedup & Diff` already puts
anything present in state but absent from the current file list into `toDelete`,
so an excluded module is removed from the index by the existing delta.

**The non-obvious part**: `Dedup & Diff` suppresses all deletions for a course
when Moodle returns an empty file list, so an outage cannot wipe the index. A
teacher tagging every module produces the same empty list, which would have left
the opted-out material indexed forever — the opt-out failing silently precisely
when applied most broadly. `Extract Files` now reports `modulesSeen` and
`modulesExcluded`, and the safety net stands down only when at least one module
was excluded and every module seen was excluded. The `> 0` guard matters: on the
empty set, "every module seen was excluded" is vacuously true, and without it a
real outage would disable the protection it exists for.

**Verified**:
- [x] Tagging an already-ingested module removes it on the next run: `1 removed,
      1 opted out`, document soft-deleted, live documents 38 -> 37
- [x] Untagging restores it on the run after: `1 new`, new document id, 37 -> 38
- [x] Tagging a page excludes both its transcript and its attached PDF
- [x] Case-insensitive: `[no-ai]` and `[NO-AI]` both match
- [x] Empty `INGEST_OPT_OUT_TAG` disables the filter entirely
- [x] A custom tag is honoured and the default no longer matches
- [x] Course-wide opt-out still deletes; a Moodle outage still suppresses deletions
- [x] Zero modules seen, and missing counters, both behave as an outage

**Review**: cross-checked with the coordinating session, which flagged the
vacuous-truth risk on the empty set. The guard was already present but not
demonstrated; probes D (zero modules seen), E (counters absent) and F (exclusions
with a non-empty list) now cover it.

---

### FEAT-004: YouTube transcript ingestion for the n8n pipeline

**Status**: completed | **Priority**: medium | **Created**: 2026-09-06 | **Completed**: 2026-09-07
**Spec**: `.s2s/specs/20260906-youtube-transcript-ingestion.md`
**Plan**: `.s2s/plans/20260906-233334-youtube-transcript-ingestion.md`
**Branch**: `feat/youtube-transcript-ingestion`

**Context**: Lecture videos are embedded as `<iframe>` inside `mod_page` HTML and
were invisible to the ingestion workflow, which only collected four document
mimetypes. For Psicologia generale that is 36 lectures (~91k words) absent from
the RAG index. Transcripts are fetched from a
[`yt-dlp-api`](https://github.com/fvadicamo/yt-dlp-api) service added to the n8n
stack, reflowed, and ingested as Markdown through the existing multipart path.

**Implementation** (commits d872784, 67856e8, 57d207b, b2fb966, 7f0b434):
- `Extract Files` emits each page module's `index.html` as a transcript
  candidate, keyed on `modname` because `mimetype` is `NULL`. The existing
  `SUPPORTED_MIMES` loop still runs over the same module, so PDFs attached to
  page modules keep ingesting.
- `Process Single File` branches on `_kind === 'page'`: extract the video id,
  fetch captions, reflow, build Markdown in memory, reuse the multipart upload.
  Pages with no embed return `skipped`; fetch failures fail that page alone.
- `Ingestion Summary` counts `skipped` separately from `unchanged`.
- `ytdlp-api` service added to `n8n/docker-compose.yml`, pinned to `:weekly`.

**Verified** (dev stack, real course 3 data):
- [x] `Extract Files` yields 71 page candidates and 35 PDF candidates — no PDF regression
- [x] 36 pages carry an embed, 35 do not; no page carries more than one
- [x] Transcripts retrieve 36/36 through the service, 91,387 words, ~2.5 s each
- [x] Reflowed text contains no mid-sentence line breaks
- [x] `Ingestion Summary` counters correct across all 32 action/status combinations, with no double counting and no new fall-through
- [x] A page edited to drop its embed no longer loops: the stale state entry is removed
- [x] `NODE_FUNCTION_ALLOW_EXTERNAL` still empty
- [x] `README.md` documents the cookie gate and the ASR limitation

**Verified in a live workflow run** (2026-09-08, after BUG-018 was resolved,
with the Vektra platform stack deliberately down):
- [x] `Extract Files` produced 106 candidates in the real pipeline — 71 page + 35 PDF
- [x] The 35 PDFs were classified `unchanged` and not re-ingested: no regression
- [x] 36 pages resolved a transcript, 35 reported `skipped`
- [x] The summary reported `35 skipped, 36 failed`, and every failure was
      `getaddrinfo ENOTFOUND vektra-stack-vektra-1` — the absent platform, not a
      code fault. Transcript retrieval itself therefore succeeded for all 36.
- [x] Failures stayed per-item and the run completed with status `success`

**Verified against the full stack** (2026-09-08, Vektra + qdrant running):
- [x] `text/markdown` ingests through `POST /api/v1/ingest`: 36 new, 35 skipped,
      0 failed, every transcript returning a `document_id`
- [x] Idempotence: an immediate second run reported 0 new, 0 updated, 0 failed
- [x] Update handling: editing one page produced exactly `1 updated`; the old
      document was soft-deleted (`deletion_reason: user_request`) before the new
      one was written, and the state entry moved to the new id. No other page
      was touched.
- [x] Graceful degradation: with `ytdlp-api` stopped, the run still completed
      (`status: success`) and only the affected page failed, with
      `getaddrinfo ENOTFOUND ytdlp-api`. Restarting the service let the next run
      re-ingest it unaided, leaving exactly 36 live transcripts in Vektra.

**Known limitation**: ASR output mis-transcribes proper nouns — observed
*"Finess Cage"* for **Phineas Gage**. Documented in `n8n/README.md` and in each
document's provenance header. Mitigation deferred; see the spec's open questions.

### FEAT-003: Per-course inline citations control (vektra-stack FEAT-021 integration)

**Status**: completed | **Priority**: low | **Created**: 2026-07-11 | **Completed**: 2026-07-18
**Origin**: v0.6.0 planning, versioning alignment check against the vektra-stack backlog
**Unblocked by**: vektra-stack FEAT-021, shipped in [vektra-stack v0.6.0](https://github.com/vektralabs/vektra-stack) (completed 2026-07-12, tagged 2026-07-13)

**Implementation** (branch `feat/per-course-inline-citations`):
- Third behavioral select `config_citations_choice` (Inherit / Yes / No) in the per-course form, with the same "Effective: value (status)" static label as the other two selects, seeded from the namespace GET on form open and frozen when the GET fails.
- `instance_config_save` extends the PATCH payload with `citations_enabled` (null on inherit, bool otherwise); the field is stripped from configdata like its siblings.
- New lang strings (`config_citations_*`) in en/it; version stamp bumped to 2026071800.

**Context**: vektra-stack FEAT-021 added a per-namespace `citations_enabled` flag (JSONB, PATCH whitelist `ALLOWED_CONFIG_TYPES`, resolved default `false`, no env var) that makes the assistant cite sources inline in the answer text (`[n]` superscript markers with the source title as tooltip, rendered by the widget). Distinct from FEAT-014 `show_sources` (visibility of the widget sources panel). The plugin reuses the GET/PATCH namespace-config flow from v0.5.0.

**Acceptance criteria**:
- [x] Third behavioral select "Inline citations" (inherit / yes / no) with the same effective-value UX as grounding mode
- [x] PATCH payload extended with `citations_enabled` (null on inherit)
- [x] Release coordinated with the vektra-stack version that ships FEAT-021 (plugin v0.6.0 pairs with stack v0.6.0)

### FEAT-001: Show diagnostic error when Vektra API connection fails

**Status**: completed | **Priority**: high | **Created**: 2026-03-22 | **Completed**: 2026-07-11
**Origin**: silent widget failure during API key reset on Kalypso

**Implementation** (branch `feat/diagnostic-error-display`):
- `vektra_client::generate_token` captures the failure detail, reusing `parse_error_envelope`; a new `get_last_token_error()` accessor exposes `{httpcode, code, message}`. HTTP 0 (network failure) maps to "Connection failed or timed out".
- New `vektra_client::redact()` strips the configured API key, `Bearer` header values, and JWT-shaped strings from all `debugging()` calls in the client and from the stored diagnostic message.
- `block_vektra::get_content`: on token failure, site admins (`moodle/site:config`) see `tokenerror_diagnostic` as block content plus a `\core\notification::error()` banner; all other users see the localized `unavailable` string instead of an empty block.
- New lang strings `tokenerror_diagnostic` and `unavailable` (en/it); version stamp bumped to 2026071100.

**Context**: When the plugin cannot generate a JWT token (API key invalid, Vektra unreachable, 401/timeout), the block silently showed nothing - no widget button, no error message. Admins saw "AI Assistant is active" but students saw a blank block. The root cause (invalid API key, network issue, container down) was invisible without checking Moodle/Vektra logs or browser console.

**Acceptance criteria**:
- [x] Block shows role-appropriate error message when token generation fails
- [x] Admin sees sanitized error code/message from Vektra API (no secrets/tokens/keys)
- [x] Student sees localized "unavailable" message
- [x] Error is logged via `debugging()` with redaction of API keys, JWTs, and authorization headers
- [x] No change when everything works (current behavior preserved)

### BUG-001: Namespace mismatch between n8n ingestion and plugin

**Status**: completed | **Priority**: high | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #15 (release v0.5.0), comment 3143946475

**Implementation** (commits 89aca9d, df17369 on branch `fix/v0.5.0-n8n-workflow`):
- Option A adopted: plugin replicates n8n slug algorithm via `\block_vektra\namespace_resolver::slugify()`.
- Algorithm (PHP and JS, identical): NFD decompose + strip combining marks → lowercase → replace `[^0-9a-z_-]+` with `-` → collapse repeated dashes → trim → truncate to 50.
- Both fall back to `course-{id}` when the resulting slug is empty.
- Parity verified with smoke tests against 10 representative inputs (PHP in Moodle container vs Node host) — identical output for valid inputs, identical fallback for empty/CJK/whitespace inputs.
- Documentation: `n8n/README.md` Namespace Convention section.
- Override behavior (explicit `course_id` / `namespace` not slugified) tracked separately as BUG-012.

**Context**: The n8n ingestion workflow (`n8n/workflows/moodle-ingest.json`) maps Moodle course shortname to Vektra namespace by **slugifying** it (lowercase + replace spaces with dashes), while the plugin uses the **raw shortname** as the fallback in the namespace resolution chain. Result: any course whose shortname contains spaces or uppercase letters silently fails — the widget queries the raw-name namespace while documents were ingested into the slugified one.

Example: shortname `"Course 101"` → ingest writes to `course-101`, widget queries `Course 101` → empty results.

**Acceptance criteria**:
- [x] Plugin and n8n agree on the same namespace derivation algorithm
- [x] Algorithm normalizes the full Vektra-allowed character set: only `[0-9a-zA-Z_-]` survives; `/`, `:`, accented characters, and other non-allowed bytes are mapped to `-` (extended after CodeRabbit comment 3144124468)
- [x] Documentation (README per-course setup) flags the convention
- [x] Existing ingested courses continue to work without re-ingestion

### BUG-004: n8n workflow uses `require('http')` only — fails on HTTPS endpoints

**Status**: completed | **Priority**: critical | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124469

**Implementation** (commits ad46bb4, 90bbfca on branch `fix/v0.5.0-n8n-workflow`):
- Both `Handle Deletions` and `Process Single File` now `require('https')` in addition to `http` and select `lib = url.protocol === 'https:' ? https : http` per request.
- Default port is `url.port || (url.protocol === 'https:' ? 443 : 80)` — explicit port still takes precedence.
- `n8n/docker-compose.yml`: `NODE_FUNCTION_ALLOW_BUILTIN` extended to include `https`.
- HTTPS deployment guidance added to `docker/README.md` and `n8n/README.md`.

**Context**: Both `Handle Deletions` and `Process Single File` code nodes in `n8n/workflows/moodle-ingest.json` imported only `const http = require('http')` and defaulted `port: url.port || 80`. Any production deployment with HTTPS Vektra (`https://vektra.example.com`) or HTTPS Moodle would either fail to connect or silently default to port 80 instead of 443. Local HTTP-only dev worked; HTTPS-fronted production was broken.

**Acceptance criteria**:
- [x] `httpReq` helper imports both `http` and `https`
- [x] Protocol detected from `URL.protocol`; appropriate module selected
- [x] Port defaults to 443 for `https:`, 80 for `http:`, with explicit `url.port` taking precedence
- [x] Change applied in both `Handle Deletions` and `Process Single File` nodes
- [x] Manual test: triggered against an HTTPS Vektra endpoint during dogfooding

### BUG-008: n8n state-file writes are not atomic

**Status**: completed | **Priority**: low | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124472

**Implementation** (commit ad46bb4 on branch `fix/v0.5.0-n8n-workflow`):
- `writeState` in both `Handle Deletions` and `Process Single File` now performs an atomic write: `writeFileSync(tmp, ...)` to a unique temp path then `renameSync(tmp, STATE_FILE)`.
- Temp filename: `STATE_FILE + '.' + Date.now() + '.' + Math.random().toString(36).slice(2) + '.tmp'` (`process.pid` is unavailable in the n8n task-runner sandbox; the timestamp-plus-random combination provides sufficient uniqueness for practical purposes).

**Context**: `Process Single File` did `readState()` → mutate → `writeState(state)` with no locking and no temp-file+rename. Safe at default `batchSize=1` with non-overlapping cron ticks, but a long ingestion overlapping the next tick (or anyone bumping `batchSize` for throughput) produced lost-update races on the JSON file.

**Acceptance criteria**:
- [x] `writeState` uses temp file + atomic rename (`writeFileSync(tmp, ...); renameSync(tmp, STATE_FILE)`)
- [ ] Optional: simple lockfile around `readState`/`writeState` with retry/backoff (deferred — not needed at current concurrency profile)
- [ ] Or: switch to a real KV store (deferred — KV migration is a larger refactor, see future tech-debt)

### BUG-009: n8n `_pendingDeletes` static data leaks across runs on partial failure

**Status**: completed | **Priority**: low | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124471

**Implementation** (commits cbe96b5, 68303dc on branch `fix/v0.5.0-n8n-workflow`):
- Option 2 adopted: pipe delete results through the data flow as a sentinel item instead of using `$getWorkflowStaticData('global')`.
- `Split Files` prepends `{_isDeleteSummary: true, namespace, courseId, deleteResults: [...]}` to the array of file items.
- `Process Single File` early-returns the sentinel unchanged: `if (file._isDeleteSummary) return [{ json: file }];`
- `Merge Course Results` extracts the sentinel via `results.find(r => r._isDeleteSummary)` and merges its `deleteResults` into the final summary.
- Bonus safety nets added in `Dedup & Diff`: propagates `_moodleError` flag from `Extract Files` to skip the diff entirely; aborts mass deletions when Moodle returns an empty file list for a course that has `>= 3` stored files (`DELETION_SAFETY_THRESHOLD`, hardcoded — tracked as TECH-002 for future configurability).

**Context**: `Split Files` used to stash per-namespace delete results in `$getWorkflowStaticData('global')._pendingDeletes[namespace]`; `Merge Course Results` was the only consumer. If anything between the two threw (uncaught process-file exception, workflow cancel, n8n restart), the entry persisted into the next scheduled run and was re-emitted — inflating totals and confusing operators.

**Acceptance criteria**:
- [ ] Either: key by `namespace + executionId` and reap stale entries on entry
- [x] Or: pipe `deleteResults` through the data flow instead of static storage

### BUG-011: n8n `JSON.parse` on ingest response fails on non-JSON body

**Status**: completed | **Priority**: medium | **Created**: 2026-04-27 | **Completed**: 2026-04-28
**Origin**: Gemini review round-4, comment 3147254494

**Implementation** (commit ad46bb4 on branch `fix/v0.5.0-n8n-workflow`):
- `JSON.parse(ingestResp.body.toString())` in `Process Single File` is now wrapped in its own `try/catch`. The parsed `body` is declared with `let body;` outside the try so subsequent code can use it.
- On parse failure: returns a structured failed item with HTTP status code and the first 100 characters of the response body (matches the existing "Download failed" preview length in the same node).
- Error format: `Vektra returned non-JSON (HTTP <code>): <preview>`
- The file is marked `status: 'failed'` with `document_id: null` and `chunk_count: 0`.

**Context**: `Process Single File` did `const body = JSON.parse(ingestResp.body.toString())` immediately after the `/api/v1/ingest` HTTP call, with no error handling. If Vektra returned a non-JSON response (502 proxy error, 504 gateway timeout, HTML error page), `JSON.parse` threw `SyntaxError: Unexpected token < in JSON at position 0`. The exception was caught by the outer `try/catch` which marked the file as `status: 'failed'` with the raw exception message — no HTTP status code, no response preview. Operators could not distinguish a Vektra outage from a corrupt document without manually correlating timestamps with proxy logs.

**Acceptance criteria**:
- [x] Wrap `JSON.parse(ingestResp.body.toString())` in try-catch inside `Process Single File`
- [x] On parse failure, return a structured failed item with HTTP status code and first 100 chars of response body
- [x] Error message format: `Vektra returned non-JSON (HTTP <code>): <preview>`
- [x] File is marked `status: 'failed'` with `document_id: null`

### BUG-013: n8n Handle Deletions drops state even on failed Vektra DELETE

**Status**: completed | **Priority**: high | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #19, comment 3154580989

**Implementation** (commit 0a9f2b0 on branch `fix/v0.5.0-n8n-workflow`):
- Track per-file outcome via a `deleted` boolean. Set it `true` only when `delResp.statusCode` is in `[200, 300)`.
- Only execute `delete state.courseFiles[namespace][file.fileurl]` when `deleted === true`. A non-2xx response or a thrown error leaves the file in state so the next cron tick retries.
- Files without `document_id` (never registered on Vektra) are still dropped from state — there is nothing on Vektra to retry.
- Non-2xx responses also produce a structured `delete_failed` entry with `HTTP <code>: <preview>` so operators see the actual cause.

**Context**: The `Handle Deletions` node previously cleaned up `state.courseFiles` unconditionally for every iteration in the `toDelete` loop. Since `httpReq` resolves on any HTTP response (including 5xx), a Vektra 500 was silently treated as `'deleted'`, the file was forgotten from state, and Vektra still held the document — permanent state drift between Moodle and Vektra. Only thrown errors (network failures) were caught; HTTP error statuses passed through.

**Acceptance criteria**:
- [x] `delete state.courseFiles[namespace][file.fileurl]` only runs when the API call returned 2xx
- [x] Non-2xx responses recorded as `delete_failed` with HTTP code and body preview
- [x] Files without `document_id` continue to be dropped (nothing to retry)

### BUG-014: n8n Process Single File misses HTTP status checks on Step 1 / Step 2

**Status**: completed | **Priority**: medium | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #19, comment 3154580999

**Implementation** (commit 0e6715f on branch `fix/v0.5.0-n8n-workflow`):
- Step 1 (delete old version on `action === 'updated'`): now captures the response and bails on non-2xx with `Old-version delete failed (HTTP <code>): <preview>`. Avoids leaving a duplicate document on Vektra.
- Step 2 (download from Moodle): added `statusCode` check against `[200, 300)` before the existing `application/json` check. Catches HTML 502/504 from a reverse proxy that the JSON-mimetype check would miss.
- Error envelopes match the BUG-011 / BUG-013 format (HTTP code + 100-char preview).

**Context**: `Process Single File` had two unchecked HTTP responses. Step 1 ignored its response entirely — a failed old-version delete still proceeded to upload the new file, leaving Vektra with two documents for the same `fileurl`. Step 2 only treated `Content-Type: application/json` as an error signal (Moodle's WS error envelope); a reverse-proxy 502 returning HTML had a different `Content-Type` and would fall through, getting uploaded to Vektra as if it were the user's document.

**Acceptance criteria**:
- [x] Step 1: bail on non-2xx with structured `Old-version delete failed (HTTP <code>): <preview>`
- [x] Step 2: bail on non-2xx with structured `Moodle download failed (HTTP <code>): <preview>` before the existing JSON-mimetype check
- [x] Error format consistent with BUG-011 / BUG-013
### BUG-005: docker-compose.yml hardcoded `:80:80` binding hostile to solo-dev

**Status**: completed | **Priority**: high | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124460

**Implementation** (commits 33f15c8 in PR #19, dff410b in PR fix/v0.5.0-batch-c):
- The `127.0.0.1:80:80` binding was removed from `docker/docker-compose.yml` (33f15c8). The loopback `127.0.0.1:${MOODLE_PORT:-10180}:80` is the only entry point; nginx fronts public traffic on the host (see `docker/nginx/https-reverse-proxy.conf.example`).
- The default `MOODLE_URL` is now `http://localhost:${MOODLE_PORT:-10180}` (dff410b) so a fresh `docker compose up` is browser-reachable without /etc/hosts edits.
- For the n8n integration use case, `n8n/README.md` documents the override path: set `MOODLE_URL=http://vektra-moodle` in `docker/.env` and add `127.0.0.1 vektra-moodle` to /etc/hosts so n8n (inside Docker) and the browser (on the host) resolve to the same name.

**Context**: `docker/docker-compose.yml` line 26 bound host port `80` unconditionally; `MOODLE_URL` defaulted to `http://vektra-moodle`. Two compounded issues: `:80:80` collided with anything else on host port 80 and required elevated privileges on Linux, while browser access to `http://localhost:10180` redirected to `http://vektra-moodle` which only resolves inside Docker. The trade-off was between "n8n out-of-the-box" and "solo dev clone + up".

**Acceptance criteria**:
- [x] `:80:80` binding either removed or made opt-in via env var (removed in 33f15c8)
- [x] Default `MOODLE_URL` restored to `http://localhost:${MOODLE_PORT:-10180}` for solo dev
- [x] n8n setup docs updated to reflect the new default and how to enable the n8n-friendly mode
### BUG-006: n8n README references non-existent `n8n publish:workflow` CLI

**Status**: completed | **Priority**: high | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124466

**Implementation** (commit 3a11ddf on branch `fix/v0.5.0-batch-c`):
- Replaced the n8n 1.x CLI guidance (`docker compose exec n8n n8n publish:workflow ...`) with two correct paths for n8n 2.x:
  - **UI** (simplest): open the workflow in the n8n editor and click **Publish**.
  - **REST API**: `curl --request="PATCH" "http://localhost:5678/api/v1/workflows/<workflow-id>/activate" --header="X-N8N-API-KEY: <your-n8n-api-key>"`
- Added a pointer to where to create the n8n API key (Settings > n8n API > Create an API key).

**Context**: `n8n/README.md` instructed users to run `docker compose exec n8n n8n publish:workflow --id=<workflow-id>` after upgrading from n8n 1.x to 2.x. The n8n CLI did NOT have a `publish:workflow` subcommand in 2.x — publishing became a UI-only feature (or REST API). Users following the guidance hit "command not found".

**Acceptance criteria**:
- [x] Replace `publish:workflow` instruction with the correct activation method (UI re-publish + REST API call snippet)
- [x] Verified on a fresh n8n 2.x install during dogfooding

### BUG-007: n8n README 409 remediation contradicts state-file architecture

**Status**: completed | **Priority**: medium | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124467

**Implementation** (commit 3a11ddf on branch `fix/v0.5.0-batch-c`):
- Replaced the obsolete "clear the workflow static data (Settings > Static Data > Clear)" instruction with a pointer to the JSON state file at `STATE_FILE_PATH` (default `/home/node/.n8n/moodle-ingest-state.json`).
- Cross-linked to the existing "Force re-processing of all files" section that already documents the correct `rm -f` command.

**Context**: The 409 Conflict troubleshooting step told users to clear "the workflow static data (Settings > Static Data > Clear)" but the workflow no longer used n8n static data for file tracking — it used a JSON file at `STATE_FILE_PATH`. The "Static Data > Clear" step was a no-op for the actual state and left users stuck.

**Acceptance criteria**:
- [x] 409 remediation step references the JSON state file (or links to "Force re-processing of all files" section)

### BUG-010: n8n API key guidance is too permissive

**Status**: completed | **Priority**: low | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3144124463

**Implementation** (commit 3a11ddf on branch `fix/v0.5.0-batch-c`):
- Added a security warning callout under Step 3 of `n8n/README.md` stating that `admin` scope grants the full admin surface (API keys, namespaces, admin endpoints) — far beyond what `DELETE /api/v1/documents/batch` needs.
- Documented operational guidance: store only in `n8n/.env` (gitignored), rotate on personnel changes or suspected leak, and switch to a narrower scope when one ships.
- Did not split into two keys yet — the Vektra backend does not yet support a dedicated delete-only scope. When it does, this entry can be reopened for the split.

**Context**: `n8n/README.md` instructed creating a single `n8n-moodle-sync` key with `["ingest", "admin"]` scopes. The `admin` scope is far broader than required; a leaked sync key would expose the full admin surface.

**Acceptance criteria**:
- [x] Explicit warning that `admin` grants full admin (with rotation/storage hygiene callout)
- [ ] Optionally split into two keys (deferred until backend ships a narrower delete scope)

### BUG-012: Document namespace override behavior in form help strings

**Status**: completed | **Priority**: low | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: review of PR `fix/v0.5.0-n8n-workflow` (BUG-001 fix)

**Implementation** (commit 44f0367 on branch `fix/v0.5.0-batch-c`):
- Updated `config_course_id_help` and `config_namespace_help` in `lang/en/block_vektra.php` and `lang/it/block_vektra.php` to:
  - Explain that empty = automatic slugification of the course shortname
  - State that explicit values are passed to Vektra as-is (no slugify)
  - Spell out the `[0-9a-zA-Z_-]` Vektra namespace charset and the silent-failure risk
  - Cross-link to `n8n/README.md` Namespace Convention section

**Context**: BUG-001 introduced a slugify algorithm for the namespace fallback chain. Explicit overrides on the block bypass slugification and are passed to Vektra as-is. This was documented in `n8n/README.md`, but the block edit form help strings did not mention it. Teachers who did not read the n8n README could set an override with rejected characters (uppercase, spaces, slashes, accents) and see silent failure.

**Acceptance criteria**:
- [x] `config_course_id_help` mentions the Vektra namespace charset `[0-9a-zA-Z_-]` constraint and the silent-failure risk
- [x] `config_namespace_help` mentions the same constraint
- [x] Both English and Italian translations updated
- [x] Cross-link to `n8n/README.md` Namespace Convention section

### TECH-001: Code-quality and documentation polish (CodeRabbit nitpicks)

**Status**: completed | **Priority**: low | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15 — review body nitpicks

**Implementation** (commits 5df0203, d599dea, c92f2e6, 3a11ddf on branch `fix/v0.5.0-batch-c`; earlier items addressed in PRs #11/#12 and PR #19):
- `.s2s/CONTEXT.md` — in-scope description updated with v0.5.0 additions (branding, behavioural controls, n8n workflow). [5df0203]
- `n8n/docker-compose.yml` — external network names parameterised via `VEKTRA_STACK_NETWORK` and `MOODLE_NETWORK` env vars. [c92f2e6]
- `n8n/.env.example` — `INGEST_CRON` quoted; new override variables documented. [c92f2e6]
- `edit_form.php` + `block_vektra.php` — namespace resolution extracted into reusable `\block_vektra\namespace_resolver` helper (PR #11 + PR #19 commit 89aca9d).
- `settings.php` — `default_primary_color` now uses `admin_setting_configcolourpicker` (Moodle-native hex/rgb/named validator) [PR #11].
- `classes/vektra_client.php` — error-envelope parser no longer emits `"[]"` / `"{}"` when nested shape is empty [PR #11].
- `CONTRIBUTING.md` — PHP lint example hardened with `-print0` / `xargs -0`. [d599dea]
- `block_vektra.php` — title reuses `$this->title` from `specialization()` [PR #11].
- `n8n/README.md` — fenced code blocks now have language identifiers (`text`, `bash`, `env`); production HTTPS guidance documented in PR #19 [3a11ddf, 7ed0e73].

**Context**: Bundle of 11 nitpicks spanning multiple files. None were bugs; all were code-quality or doc improvements addressed across PRs #11/#12, PR #19, and Batch C.

**Acceptance criteria**:
- [x] All items above addressed across the listed PRs

### TECH-002: `DELETION_SAFETY_THRESHOLD` in n8n workflow is hardcoded

**Status**: completed | **Priority**: low | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: review of PR `fix/v0.5.0-n8n-workflow` (BUG-009 fix introduced safety net)

**Implementation** (commit 46217ff on branch `fix/v0.5.0-batch-c`):
- `Dedup & Diff` reads `DELETION_SAFETY_THRESHOLD` from `$env.DELETION_SAFETY_THRESHOLD`, defaulting to 3 when unset. Non-numeric or negative values fall back to 3; setting `0` disables the safety net entirely.
- When the safety triggers, the node logs `[Dedup & Diff] Empty-course safety triggered for namespace=...: N stored files preserved (threshold=N)` so operators can audit the bypass via n8n execution logs.
- Wired through `n8n/.env.example` and `n8n/docker-compose.yml`; documented in the n8n README env-var table.

**Context**: `Dedup & Diff` skipped all deletions when Moodle returned an empty file list for a course with `>= 3` stored files. The default of 3 was reasonable but hardcoded; large courses always benefited from the safety, courses with 1-2 docs bypassed it without notice. Operators had no signal when the safety triggered.

**Acceptance criteria**:
- [x] Threshold sourced from `$env.DELETION_SAFETY_THRESHOLD` with default `3`
- [x] Documented in `n8n/README.md` and `n8n/.env.example`
- [x] Emit a clear log entry when the safety triggers (operator visibility)

### BUG-015: docker-entrypoint sed pattern not robust

**Status**: completed | **Priority**: medium | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #20, comments 3155335883 + 3155335915 (sister comments)

**Implementation** (commit 5c2253a on branch `fix/v0.5.0-batch-c`):
- Address pattern hardened from `/^\$CFG->wwwroot/` to `/^\s*\$CFG->wwwroot\s*=/`. The `=` anchor prevents accidental match on unrelated assignments like `$CFG->wwwroot_backup = ...`; the `\s*` prefix tolerates indented config files.
- File path literal `/var/www/html/config.php` now quoted in both `grep` and `sed` invocations per repository shell convention (`.claude/CLAUDE.md`).
- Verified against three fixtures: standard config, indented config, and config with both `$CFG->wwwroot = ...` and `$CFG->wwwroot_backup = ...` (no double-injection).

**Context**: The `reverseproxy`/`sslproxy` injection logic added in PR #19 (commit 545e179, refined in PR #20 commit b17fb91) used `/^\$CFG->wwwroot/` as the sed address. This matched any line starting with `$CFG->wwwroot` regardless of suffix or `=` operator, so configurations with multiple wwwroot-prefixed variables would receive duplicate injections. It also failed silently on indented config.php files.

**Acceptance criteria**:
- [x] Address pattern requires `\s*` prefix and `=` anchor
- [x] File path quoted in grep + sed
- [x] No regression on standard config.php

### BUG-016: n8n safety-net preserved files invisible in Ingestion Summary

**Status**: completed | **Priority**: medium | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #20, comment 3155335938

**Implementation** (commit fba0c4e on branch `fix/v0.5.0-batch-c`):
- `Dedup & Diff` now populates `unchanged` with one minimal entry per stored file (`{fileurl, filename, uniqueFilename, _safetyPreserved: true}`) when the safety net triggers.
- These entries flow through `No Changes` (mapped to `{action: 'unchanged', status: 'skipped'}`) and `Ingestion Summary` (counted in `totalUnchanged`), so the final summary now reports the correct N preserved files instead of `0`.
- The console.log "Empty-course safety triggered" message added in TECH-002 still fires for execution-detail visibility.

**Context**: TECH-002 made `DELETION_SAFETY_THRESHOLD` configurable and added a console.log when triggered. But the visible signal in the n8n UI summary still showed `0 unchanged` when the safety preserved N files, because `Dedup & Diff` returned `unchanged: []` while reporting `summary.unchanged: N` only in its local node output. Operators could only see the safety event by drilling into execution logs.

**Acceptance criteria**:
- [x] `unchanged` array populated with one entry per stored file when safety triggers
- [x] `Ingestion Summary` reports the preserved count (not `0`)
- [x] No regression on normal flows (verified across 5 scenarios)

### BUG-002: `!empty()` resolution treats `'0'` as empty in namespace/course_id chain

**Status**: completed | **Priority**: medium | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #15, comments 3143946476 + 3143946478

**Implementation** (PR #18 merged 2026-04-26 — commits 165dab0, eaa5406):
- Created `\block_vektra\namespace_resolver` static helper class with `resolve()` and `resolve_course_id()` methods using the `is_string($x) && $x !== ''` pattern.
- Refactored `block_vektra::instance_config_save`, `block_vektra::get_content`, and `edit_form_block_vektra::resolve_namespace` to delegate to the shared resolver — no more drift across the three sites.
- Manual test: setting `course_id` to `'0'` now resolves to `'0'`, not to the shortname.

**Context**: `block_vektra::instance_config_save` and `block_vektra::get_content` previously used `!empty($data->namespace)` / `!empty($data->course_id)` to resolve effective values. Since these fields are typed `PARAM_ALPHANUMEXT`, the literal string `'0'` is valid but `!empty('0')` returns `false`, causing unintended fallback to the next chain level. The fix replaces the pattern with `is_string($x) && $x !== ''` everywhere, centralised in `namespace_resolver`.

**Acceptance criteria**:
- [x] All three namespace/course_id resolution sites use `is_string($x) && $x !== ''`
- [x] Manual test: `course_id = '0'` resolves to `'0'`, not to shortname

### BUG-003: Missing maxlength validation on welcome_message form field

**Status**: completed | **Priority**: low | **Created**: 2026-04-26 | **Completed**: 2026-04-28
**Origin**: Gemini review on PR #15, comment 3143946480

**Implementation** (PR #18 merged 2026-04-26):
- `edit_form.php::specific_definition` now calls `addRule('config_welcome_message', get_string('maximumchars', '', 500), 'maxlength', 500, 'client')`.
- An inline doc comment clarifies that the `'client'` flag in HTML_QuickForm means "client + server" — server-side validation runs unconditionally per `lib/pear/HTML/QuickForm.php::validate()`. The flag only controls JS generation. (Was a Gemini false-positive in PR #18 review; documented to prevent re-flagging.)

**Context**: The implementation plan called for `config_welcome_message` to enforce `maxlength=500` via `addRule`. The pre-PR-#18 implementation only declared `PARAM_TEXT`, which protected DB storage but allowed arbitrarily long input through the form with no UX feedback. The fix adds the rule for both client-side message and server-side reject.

**Acceptance criteria**:
- [x] `addRule('config_welcome_message', maximumchars(500), 'maxlength', 500)` in `edit_form.php::specific_definition`

### BUG-017: n8n `_moodleError` not propagated to Ingestion Summary

**Status**: completed | **Priority**: medium | **Created**: 2026-04-28 | **Completed**: 2026-04-28
**Origin**: CodeRabbit review on PR #15, comment 3156051934 (post-Batch C round)

**Implementation** (commit 73d5fbb on branch `fix/v0.5.0-batch-d`):
- `No Changes` node now detects `input._moodleError` and emits a single courseResult with `action: 'sync', status: 'failed'` and the `errorDetail` preview (200 chars).
- Ingestion Summary increments `totalFailed` and adds the entry to the details list with the underlying Moodle error message, so operators see the cause without reading execution logs.
- Verified end-to-end across 5 scenarios: _moodleError, normal no-changes, safety-net (BUG-016 still works), deletes-only (BUG-013/014 intact), edge case with stale data alongside _moodleError.

**Context**: The `_moodleError` flag introduced in PR #19 prevented Dedup & Diff from treating a malformed Moodle response as a deletion trigger, but the propagation stopped there: Dedup & Diff returned empty arrays, the data flowed through `Has Deletions? -> Has Ingestions? -> No Changes` (which only iterated `input.unchanged`, also empty), and Ingestion Summary received zero items for that course. Net effect: a Moodle WS failure was reported as `0 new / 0 updated / 0 removed / 0 unchanged / 0 failed` — completely invisible.

**Acceptance criteria**:
- [x] `No Changes` detects `_moodleError` and emits a structured failed item
- [x] Ingestion Summary counts the entry as `failed`
- [x] No regression on normal / safety-net / deletes-only flows

### FEAT-002: AJAX endpoint for automatic widget token refresh

**Status**: completed | **Priority**: high | **Created**: 2026-03-23 | **Completed**: 2026-03-23
**Origin**: vektra-stack FEAT-009 (widget data-token-refresh-url support)

**Implementation** (shipped in v0.3.0 — commits ead7694, 6a8bb9c, 8b6d452, af8fcb0):
- `ajax.php` accepts authenticated requests via Moodle session, verifies sesskey for CSRF protection, checks user capability, and generates a new JWT via `vektra_client` using the API key from plugin config.
- `block_vektra::get_content()` adds `data-token-refresh-url` to the widget script tag, pointing to the AJAX endpoint with the block instance ID.
- Defensive `is_object()` validation on configdata deserialization prevents type-juggling errors when configdata is empty/malformed.

**Context**: The vektra-chat.js widget (vektra-stack) supports token auto-refresh via `data-token-refresh-url` (FEAT-009). When the JWT expires (default 1h), the widget POSTs to that URL and expects a `{"token": "..."}` response. Without this endpoint refresh fails silently and the user sees "Invalid or expired dashboard token" after 1h of session.

**Tracker drift note**: status was left at `in_progress` in the backlog after the v0.3.0 ship. Caught during the v0.5.0 release audit (2026-04-30) and corrected here.

**Acceptance criteria**:
- [x] AJAX endpoint generates new JWT for authenticated user/course
- [x] Endpoint verifies Moodle session and sesskey (CSRF)
- [x] `data-token-refresh-url` added to widget script tag
- [x] Token refresh transparent to user (no reload)
- [x] Refresh error handled by widget (localized message)
