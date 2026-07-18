# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

<!--
Convention (Keep a Changelog 1.1.0):
- Add new entries under "[Unreleased]" using sections: Added, Changed,
  Deprecated, Removed, Fixed, Security.
- At release time: rename "[Unreleased]" to "[X.Y.Z] - YYYY-MM-DD" AND
  add a fresh empty "[Unreleased]" block above it. The file must always
  have an "[Unreleased]" section at the top, even if empty.
- Releases are listed newest-first below "[Unreleased]".
- See CONTRIBUTING.md > Changelog for the full process.
-->

## [Unreleased]

### Added

- **Per-course form — Inline citations** (FEAT-003): third behavioral select
  (`Inherit` / `Yes` / `No`) controlling the per-namespace `citations_enabled`
  flag introduced by
  [vektra-stack v0.6.0](https://github.com/vektralabs/vektra-stack) (FEAT-021).
  When enabled, the assistant cites its sources inline in the answer text
  (`[n]` markers with the source title on hover). Same inherit/override UX,
  effective-value label, and PATCH flow as the existing grounding-mode and
  show-sources selects; default is inherit (off).
- **Role-aware error display on token failure** (FEAT-001): when the plugin
  cannot generate a widget token (invalid API key, unreachable backend,
  timeout), site admins now see the sanitized Vektra error code and message
  in the block plus an error banner, instead of a silent empty block;
  students see a localized "assistant unavailable" notice. API keys, JWTs,
  and Authorization headers are redacted from debug logs and diagnostics.

### Fixed

- Error-code and message parsing (`vektra_client::parse_error_envelope`)
  now reads the Vektra REQ-010 envelope at the document root
  (`{"error": {...}}`) as well as the older `detail`-nested form
  (`{"detail": {"error": {...}}}`). The platform moved the envelope to the
  root in DEBT-034; without this, the FEAT-001 diagnostic display would have
  fallen back to a bare `HTTP <code>` instead of the sanitized Vektra code
  and message. The nested form is still accepted, so the plugin works
  against backends on either side of that change.

## [0.5.0] - 2026-04-30

Instructor configuration UI and white-label site settings. Aligns the plugin
release tag with [vektra-stack v0.5.0](https://github.com/vektralabs/vektra-stack)
since the two ship together for FEAT-014 (per-course grounding mode and
source citation visibility).

### Added

- **Site settings — Branding**: `Primary color` and `Widget logo URL`
  (plugin-global, no per-course override by design).
- **Site settings — Attribution**: `Attribution text` and `Attribution link`
  (plugin-global "powered by" surface).
- **Per-course form — Welcome message**: optional textarea greeting shown
  when the chat opens.
- **Per-course form — Behavior (Vektra)**: `Grounding mode` (inherit / strict /
  hybrid) and `Show sources` (inherit / yes / no), persisted on the Vektra RAG
  namespace via `PATCH /api/v1/admin/namespaces/{ns}/config`. Pre-populated
  from a `GET` on form open with a 2 s timeout; the resolved value is shown
  next to each select.
- **Course-aware default title**: when the block has no instance title
  override, the heading defaults to "Assistant for *{course name}*"
  (localized in `en` and `it`) instead of the generic plugin name.
- **n8n ingestion workflow** (`n8n/`): optional component that polls Moodle
  Web Services and syncs course materials into Vektra RAG automatically (PDF,
  DOCX, PPTX, Markdown). See `n8n/README.md` for setup.
- **HTTP client**: `vektra_client::get_namespace_config` and
  `patch_namespace_config` covering the vektra-stack v0.5.0 admin endpoints,
  with structured error-envelope parsing (`ERR-ADMIN-005/006/007` surfaced
  cleanly to the form).

### Changed

- **User-facing branding**: README and external-facing docs now use
  "Vektra RAG for Moodle" to align with the parent product
  ([vektra-stack](https://github.com/vektralabs/vektra-stack)) rebrand
  to **Vektra RAG** (coordinated with
  [vektralabs/vektra-stack#70](https://github.com/vektralabs/vektra-stack/pull/70)).
  In-product labels (the `pluginname` "Vektra AI Assistant" shown in
  Moodle UI, the per-course default block title "Assistant for {course}",
  and admin setting field labels) are unchanged. Repo name, PHP class
  names, and code identifiers are unchanged.
- **Render path**: `block_vektra::get_content` now reads the course from
  `$this->page->course` instead of the `$COURSE` global, matching Moodle
  block-context guidance.
- **Namespace resolution**: explicit namespace override > `course_id`
  override > course shortname, mirroring the backend default chain on
  the JWT path.
- **Block title**: still respects an instance title override, but the
  empty-config default is now the localized course-aware string above.
- Lang files (`en`, `it`) extended for all the new fields and the new
  status / save-warning strings.

### Fixed

- Namespace / course ID resolution no longer treats the literal string
  `'0'` as an unset override: the `!empty()` checks were replaced with a
  shared `\block_vektra\namespace_resolver` helper using an explicit
  `is_string($x) && $x !== ''` test across all three resolution sites.
- `\curl::patch()` is now used for namespace PATCH (was `\curl::post()`
  with `CUSTOMREQUEST=PATCH`, which Moodle's wrapper coerced back to POST
  and the server rejected with 405).
- Form open with the Vektra RAG API unreachable no longer silently clears
  existing namespace overrides on save: the behavioral selects are frozen
  in the rendered form and the save flow skips the PATCH entirely (with a
  `notification::info` to the teacher) until the form is reopened with a
  successful read.
- Behavioral fields (`grounding_mode`, `show_sources_choice`) and the
  internal `get_ok` form marker are stripped from the form data before
  serialization, so they no longer leak into `mdl_block_instances.configdata`.

## Older releases

For releases prior to v0.5.0, see the
[GitHub Releases page](https://github.com/vektralabs/vektra-moodle/releases).
