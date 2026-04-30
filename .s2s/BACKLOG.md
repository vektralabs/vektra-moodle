# vektra-moodle Backlog

**Updated**: 2026-04-28 (Batch D — PR #15 post-Batch-C review)
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
