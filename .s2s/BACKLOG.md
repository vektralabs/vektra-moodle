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
- [ ] Admin sees error code/message from Vektra API
- [ ] Student sees localized "unavailable" message
- [ ] Error is logged via `debugging()` for Moodle logs
- [ ] No change when everything works (current behavior preserved)

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
