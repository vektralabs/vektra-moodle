# vektra-moodle Backlog

**Updated**: 2026-03-17
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

<!-- Move items here when done -->
