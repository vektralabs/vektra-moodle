# vektra-moodle Backlog

**Updated**: 2026-04-26
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

### BUG-001: Namespace mismatch between n8n ingestion and plugin

**Status**: planned | **Priority**: high | **Created**: 2026-04-26
**Origin**: Gemini review on PR #15 (release v0.5.0), comment 3143946475

**Context**: The n8n ingestion workflow (`n8n/workflows/moodle-ingest.json`) maps Moodle course shortname to Vektra namespace by **slugifying** it (lowercase + replace spaces with dashes), while the plugin uses the **raw shortname** as the fallback in the namespace resolution chain. Result: any course whose shortname contains spaces or uppercase letters silently fails — the widget queries the raw-name namespace while documents were ingested into the slugified one.

Example: shortname `"Course 101"` → ingest writes to `course-101`, widget queries `Course 101` → empty results.

**Proposed approach** (decide before fix):
- Option A (recommended): plugin slugifies shortname to match n8n behavior. Lowest risk, no migration; preserves existing ingestions.
- Option B: n8n stops slugifying. Breaks existing ingestions; requires re-ingest.
- Option C: document the constraint and require teachers to set explicit `course_id` override on the block when shortname is non-slug-safe.

**Acceptance criteria**:
- [ ] Plugin and n8n agree on the same namespace derivation algorithm
- [ ] Documentation (README per-course setup) flags the convention
- [ ] Existing ingested courses continue to work without re-ingestion

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

<!-- Move items here when done -->
