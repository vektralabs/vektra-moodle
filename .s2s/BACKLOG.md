# vektra-moodle Backlog

**Updated**: 2026-04-28 (PR #19 review round)
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

### FEAT-001: Show diagnostic error when Vektra API connection fails

**Status**: planned | **Priority**: high | **Created**: 2026-03-22
**Origin**: silent widget failure during API key reset on Kalypso

**Context**: When the plugin cannot generate a JWT token (API key invalid, Vektra unreachable, 401/timeout), the block silently shows nothing - no widget button, no error message. Admins see "AI Assistant is active" but students see a blank block. The root cause (invalid API key, network issue, container down) is invisible without checking Moodle/Vektra logs or browser console.

**Proposed approach**: differentiate error display by role in `block_vektra.php`:
- **Admin/teacher** (`has_capability('moodle/site:config')`): show diagnostic message with error details ("Vektra API error: 401 Unauthorized. Check plugin settings > API key.")
- **Student**: show "L'assistente non e' al momento disponibile" (localized)

Use Moodle's `\core\notification::error()` for admin-visible banner in addition to block content.

**Acceptance criteria**:
- [ ] Block shows role-appropriate error message when token generation fails
- [ ] Admin sees sanitized error code/message from Vektra API (no secrets/tokens/keys)
- [ ] Student sees localized "unavailable" message
- [ ] Error is logged via `debugging()` with redaction of API keys, JWTs, and authorization headers
- [ ] No change when everything works (current behavior preserved)

### FEAT-002: AJAX endpoint for automatic widget token refresh

**Status**: in_progress | **Priority**: high | **Created**: 2026-03-23
**Origin**: vektra-stack FEAT-009 (widget data-token-refresh-url support)

**Context**: The vektra-chat.js widget (vektra-stack) supports token auto-refresh via `data-token-refresh-url` (FEAT-009). When the JWT expires (default 1h), the widget POSTs to that URL and expects a `{"token": "..."}` response. The Moodle plugin does not expose this endpoint and does not pass the attribute in the script tag, so refresh fails silently and the user sees "Invalid or expired dashboard token" after 1h of session.

**Proposed approach**:
1. Create `ajax.php` that accepts authenticated requests via Moodle session, verifies the user is logged in and enrolled in the course, and generates a new JWT calling Vektra `/api/v1/learn/tokens` with the API key from plugin config
2. In `block_vektra.php`, add `data-token-refresh-url` to the script tag attributes, pointing to the AJAX endpoint
3. The endpoint must verify sesskey for CSRF protection

**Acceptance criteria**:
- [ ] AJAX endpoint generates new JWT for authenticated user/course
- [ ] Endpoint verifies Moodle session and sesskey (CSRF)
- [ ] `data-token-refresh-url` added to widget script tag
- [ ] Token refresh transparent to user (no reload)
- [ ] Refresh error handled by widget (localized message)

### BUG-002: `!empty()` resolution treats `'0'` as empty in namespace/course_id chain

**Status**: planned | **Priority**: medium | **Created**: 2026-04-26
**Origin**: Gemini review on PR #15, comments 3143946476 + 3143946478

**Context**: `block_vektra::instance_config_save` and `block_vektra::get_content` use `!empty($data->namespace)` / `!empty($data->course_id)` to resolve the effective values. Since these fields are typed `PARAM_ALPHANUMEXT`, the literal string `'0'` is a valid value but `!empty('0')` returns `false`, causing an unintended fallback to the next level in the chain. The namespace lookup in `get_content` already uses the correct `is_string($ns) && $ns !== ''` pattern; only the other sites need to be aligned.

**Affected lines**:
- `block_vektra.php:148-154` (instance_config_save namespace chain)
- `block_vektra.php:213-214` (get_content course_id resolution)
- `edit_form.php:251-260` (resolve_namespace — same pattern)

**Acceptance criteria**:
- [ ] All three namespace/course_id resolution sites use `is_string($x) && $x !== ''` (matching the pattern already used in `get_content` for `namespace`)
- [ ] Manual test: setting `course_id` to `'0'` resolves to `'0'`, not to shortname

### BUG-003: Missing maxlength validation on welcome_message form field

**Status**: planned | **Priority**: low | **Created**: 2026-04-26
**Origin**: Gemini review on PR #15, comment 3143946480 — gap from plan Phase C5

**Context**: The implementation plan (Phase C5) called for `config_welcome_message` to enforce `maxlength=500` via `addRule` (client-side message + server-side reject). The current implementation only declares `PARAM_TEXT`, which protects DB storage but allows arbitrarily long input through the form with no UX feedback. No security or overflow risk in `mdl_block_instances.configdata`; this is a UX/validation gap.

**Acceptance criteria**:
- [ ] `addRule('config_welcome_message', maximumchars(500), 'maxlength', 500)` in `edit_form.php::specific_definition`
- [ ] Optional: `validation()` method to trim + reject > 500 server-side

### BUG-005: docker-compose.yml hardcoded `:80:80` binding hostile to solo-dev

**Status**: planned | **Priority**: high | **Created**: 2026-04-26
**Origin**: CodeRabbit review on PR #15, comment 3144124460

**Context**: `docker/docker-compose.yml` line 26 binds host port `80` unconditionally; line 28 defaults `MOODLE_URL=http://vektra-moodle`. Two compounded issues:
1. `:80:80` collides with anything already on host port 80 (apache, nginx, other containers) and requires elevated privileges on Linux unless rootless Docker is configured.
2. Browser access to `http://localhost:10180` redirects to `http://vektra-moodle`, which only resolves inside Docker. Solo dev needs hosts-file edit or explicit `MOODLE_URL` override.

The `:80:80` binding exists for n8n integration (n8n's default also expects `http://vektra-moodle`); the conflict is between "n8n out-of-the-box" and "solo dev clone + up".

**Note** (2026-04-28): partial mitigation already shipped in commit 33f15c8 on branch `fix/v0.5.0-n8n-workflow` — the `127.0.0.1:80:80` binding was removed and the nginx-fronted layout is now the documented HTTPS path. The remaining AC (default `MOODLE_URL` for solo dev + opt-in n8n-friendly mode) still applies.

**Acceptance criteria**:
- [x] `:80:80` binding either removed or made opt-in via env var (e.g., `MOODLE_HOST_PORT_80=true`) — removed (commit 33f15c8)
- [ ] Default `MOODLE_URL` restored to `http://localhost:${MOODLE_PORT:-10180}` for solo dev
- [ ] n8n setup docs updated to reflect the new default and how to enable the n8n-friendly mode

### BUG-006: n8n README references non-existent `n8n publish:workflow` CLI

**Status**: planned | **Priority**: high | **Created**: 2026-04-26
**Origin**: CodeRabbit review on PR #15, comment 3144124466

**Context**: `n8n/README.md` lines 124-127 instruct users to run `docker compose exec n8n n8n publish:workflow --id=<workflow-id>` after upgrading from n8n 1.x to 2.x. The n8n CLI does NOT have a `publish:workflow` subcommand in 2.x — publishing is a UI-only feature now (or via REST API). Users following this guidance hit "command not found".

**Suggested replacement** (from CodeRabbit review reply 3144139479):
- **REST API approach** (programmatic activation):
  ```bash
  curl --request="PATCH" "http://localhost:5678/api/v1/workflows/<workflow-id>/activate" \
    --header="X-N8N-API-KEY: <your-n8n-api-key>"
  ```
- **UI-only path**: open the workflow in the n8n editor and click **Publish** (simplest for users without API key access)

**Acceptance criteria**:
- [ ] Replace `publish:workflow` instruction with the correct activation method (UI re-publish, or REST API call snippet)
- [ ] Verify on a fresh n8n 2.x install

### BUG-007: n8n README 409 remediation contradicts state-file architecture

**Status**: planned | **Priority**: medium | **Created**: 2026-04-26
**Origin**: CodeRabbit review on PR #15, comment 3144124467

**Context**: `n8n/README.md` lines 209-211 tell users to clear "the workflow static data (Settings > Static Data > Clear)" to resolve a 409 Conflict. The workflow no longer uses n8n static data for file tracking — it uses a JSON file at `STATE_FILE_PATH` (default `/home/node/.n8n/moodle-ingest-state.json`), as documented just below in lines 215-219. The "Static Data > Clear" step is a no-op for this state and leaves users stuck.

**Acceptance criteria**:
- [ ] 409 remediation step references the JSON state file (or links to "Force re-processing of all files" section)

### BUG-010: n8n API key guidance is too permissive

**Status**: planned | **Priority**: low | **Created**: 2026-04-26
**Origin**: CodeRabbit review on PR #15, comment 3144124463

**Context**: `n8n/README.md` lines 67-70 instruct creating a single `n8n-moodle-sync` key with `["ingest", "admin"]` scopes. The `admin` scope is far broader than what `DELETE /api/v1/documents/batch` requires; a leaked sync key would expose the full admin surface (api-keys, namespaces, etc.).

**Acceptance criteria**:
- [ ] Either: explicit warning that `admin` grants full admin (with rotation/storage hygiene callout)
- [ ] Or (preferred if backend supports it): split into two keys (ingest-only + narrower delete-capable)

### BUG-012: Document namespace override behavior in form help strings

**Status**: planned | **Priority**: low | **Created**: 2026-04-28
**Origin**: review of PR `fix/v0.5.0-n8n-workflow` (BUG-001 fix)

**Context**: BUG-001 introduced a slugify algorithm for the namespace fallback chain (course shortname → slug). Explicit overrides on the block (`config_course_id`, `config_namespace`) bypass slugification and are passed to Vektra as-is. This is documented in `n8n/README.md` (Namespace Convention section, added in commit df17369), but the block edit form help strings (`config_course_id_help`, `config_namespace_help` in `lang/en/block_vektra.php` and `lang/it/block_vektra.php`) do not mention this. Teachers who do not read the n8n README may set an override containing characters Vektra rejects (uppercase, spaces, slashes, accented chars) and see silent failure.

**Deferred**: scheduled for Batch C (docs & infra). Not blocking the current n8n workflow PR.

**Acceptance criteria**:
- [ ] `config_course_id_help` mentions the Vektra namespace charset `[0-9a-zA-Z_-]` constraint and the silent-failure risk
- [ ] `config_namespace_help` mentions the same constraint
- [ ] Both English and Italian translations updated
- [ ] Cross-link to `n8n/README.md` Namespace Convention section

### TECH-001: Code-quality and documentation polish (CodeRabbit nitpicks)

**Status**: planned | **Priority**: low | **Created**: 2026-04-26
**Origin**: CodeRabbit review on PR #15 — review body nitpicks

**Context**: Bundle of 11 nitpicks spanning multiple files. None are bugs; all are code-quality or doc improvements that can be addressed together in a single low-risk pass.

**Items**:
- `.s2s/CONTEXT.md:35` — update "in scope" to mention v0.5.0 additions (branding, behavioral controls)
- `n8n/docker-compose.yml:31-39` — parameterize external network names via env vars (`${VEKTRA_STACK_NETWORK:-vektra-stack_default}`, `${MOODLE_NETWORK:-docker_default}`)
- `n8n/.env.example:15` — quote `INGEST_CRON` value for portability across dotenv parsers
- `edit_form.php:250-262` + `block_vektra.php:148-154` — extract namespace resolution into a reusable helper (e.g., `vektra_client::resolve_namespace($block, $page)`) to prevent drift (also addresses BUG-002)
- `settings.php:83-89` — stricter validation on `default_primary_color` (custom closure restricted to hex/rgb/named CSS colors)
- `classes/vektra_client.php:243-283` — micro-improvement: avoid emitting `"[]"` / `"{}"` when unknown nested error shape is empty (fall back to `HTTP {code}`)
- `CONTRIBUTING.md:69` — harden the example PHP lint command against unusual filenames (`-print0` / `xargs -0`)
- `block_vektra.php:256-259` — reuse `$this->title` (set in `specialization()`) instead of recomputing the default title
- `n8n/README.md:7` — add language identifiers to fenced code blocks
- `n8n/README.md:223-230` — production guidance should explicitly call out TLS and credential handling (cross-link with BUG-004 once HTTPS support lands)

**Acceptance criteria**:
- [ ] All items above addressed (one PR, batched)

### TECH-002: `DELETION_SAFETY_THRESHOLD` in n8n workflow is hardcoded

**Status**: planned | **Priority**: low | **Created**: 2026-04-28
**Origin**: review of PR `fix/v0.5.0-n8n-workflow` (BUG-009 fix introduced safety net)

**Context**: `Dedup & Diff` in `n8n/workflows/moodle-ingest.json` skips all deletions when Moodle returns an empty file list for a course that has `>= 3` stored files (`DELETION_SAFETY_THRESHOLD = 3`). The threshold guards against transient WS outages causing data loss in Qdrant. The default of 3 is reasonable for typical course sizes but is hardcoded; large courses always benefit from the safety, courses with 1-2 docs bypass it without notice. Operators have no signal when the safety triggers (the workflow returns an empty diff and continues silently).

**Acceptance criteria**:
- [ ] Threshold sourced from `$env.DELETION_SAFETY_THRESHOLD` with default `3`
- [ ] Documented in `n8n/README.md` and `n8n/.env.example`
- [ ] Optional: emit a clear log/summary entry when the safety triggers (operator visibility)

---

<!-- Add items here using the format below -->
<!--
### FEAT-001: Feature Title

**Status**: planned | **Created**: 2026-03-17

**Context**: Description of the feature or task.

**Traceability** (optional):
- **Originated from**: IDEA-001 | brainstorm:{session-id}
- **Implements**: REQ-001, REQ-002
- **Plan**: {plan-id}

**Acceptance Criteria**:
- [ ] Criterion 1
- [ ] Criterion 2
-->

---

## In Progress

<!-- Move items here when work begins -->

---

## Completed

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
