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

- **n8n — only courses with the Vektra block are indexed** (FEAT-010): the
  pipeline asks Moodle which blocks each course carries and indexes only those
  with the Vektra block, instead of every course on the installation. Adding the
  block to a course indexes its material on the next run; removing the block
  deletes that material from the index. Requires
  `core_block_get_course_blocks` on the `n8n Ingestion` web service. A block
  inherited from a site or category context does not count as opting in, and a
  failed lookup leaves the course's index untouched rather than pruning it.

- **n8n — one template, many Moodle instances** (FEAT-007): instance identity
  moved into a `Config` node at the head of the workflow, and the JSON to import
  is generated per instance from a single template
  (`node n8n/scripts/build-instance.mjs <instance>`). The generated workflows are
  identical except that node, so a change applies to every instance instead of
  being hand-merged into each. Instances are declared in `n8n/instances/`.

  This exists because the second Moodle, `mooc.unical.it`, had drifted months
  behind on a hand-maintained copy: missing three features and hardcoding both
  its web-service token and its state path.

### Changed

- **The workflow no longer defaults its state path.** `Config` refuses to start
  when the path is absent instead of falling back to a shared default. Two
  workflows on one state file each see the other's documents as present in state
  but absent from Moodle, delete them from the index, and re-ingest them on the
  next run — a loop the empty-course guard cannot catch, because neither file
  list is ever empty. `STATE_FILE_PATH` now has a default in
  `docker-compose.yml` so the existing instance is unaffected; a second instance
  must declare its own.

### Security

- **The second instance's web-service token leaves the workflow.** It was
  hardcoded in three nodes, which put it in the n8n database, in every export and
  in every backup. `Config` reads it from the environment, as the main instance
  already did.

### Added

- **n8n — module visibility propagated to the ingest API** (FEAT-006): a module
  hidden in Moodle (`visible = 0`) is still ingested, but the request now carries
  `hidden_from_students: true` in its `metadata` field, so the backend can use
  the content without exposing the source. The key is omitted for visible
  modules, which the backend reads as false. Field name and shape are fixed by
  the vektra-stack ingest contract; `uservisible` is deliberately not used, since
  with an admin token it stays true even for hidden modules.

  Visibility is part of change detection: hiding a module touches no file, so
  `timemodified` alone would classify the flip as unchanged and the document
  would keep a stale flag indefinitely. The stored state records the visibility
  each document was ingested with, and a difference marks the file as updated —
  which deletes the old document before re-ingesting. That delete is required,
  not tidiness: re-ingesting unchanged content returns `exists` and ignores the
  metadata of that request (measured against the running backend).

  Upgrading is safe in both directions. State written before this change has no
  visibility recorded, which reads as visible: files whose module is still
  visible stay unchanged, and files whose module is already hidden read as a
  flip and migrate through the re-ingest path, acquiring the flag they never
  had. The second case is the intended migration rather than a side effect.

  Modules that are visible but carry availability restrictions (group, date) are
  reported as visible; restriction-aware visibility is out of scope.

### Added

- **n8n — teacher opt-out by title tag** (FEAT-005): a module whose title
  contains `[no-ai]` is excluded from the index. The tag is configurable with
  `INGEST_OPT_OUT_TAG`, matched case-insensitively anywhere in the title, and
  an empty value disables the feature. Tagging a module excludes everything it
  contributes — its documents and, for a page, its video transcript.

  Removal is not merely a skip: a module tagged after it was already ingested
  is deleted from the index on the next run, and untagging it restores the
  material on the run after that. The ingestion summary counts excluded modules
  as `opted out`, separately from the `removed` count of documents actually
  deleted, so a tag doing its job is distinguishable from a broken extractor.

### Fixed

- **n8n — a course-wide opt-out is no longer mistaken for a Moodle outage**:
  deletions are suppressed for a course when Moodle returns an empty file list,
  so an outage cannot wipe the index. Tagging every module in a course produces
  the same empty list, which would have left the opted-out material indexed
  forever. `Extract Files` now reports how many modules it saw and excluded, and
  the safety net stands down only when at least one module was actually
  excluded and every module seen was excluded — never on the empty set, where
  the naive test is vacuously true.

### Added

- **n8n — YouTube transcript ingestion** (FEAT-004): lecture videos embedded in
  Moodle page modules are now ingested as transcripts. The workflow collects
  each page's `index.html`, extracts the embedded YouTube id, and fetches
  captions from a new `ytdlp-api` service in the n8n stack
  ([yt-dlp-api](https://github.com/fvadicamo/yt-dlp-api), pinned to `:weekly`
  so upstream tracking of YouTube's changes is inherited rather than
  maintained here). The caption text is reflowed out of its subtitle line
  wraps and ingested as Markdown with a provenance header, through the
  multipart path the workflow already used for documents.
  `NODE_FUNCTION_ALLOW_EXTERNAL` stays empty: the workflow reaches the service
  over HTTP, as it already does for Moodle and Vektra.
- **n8n — `skipped` counted separately in the ingestion summary**: pages with
  no embed report `skipped` instead of being folded into `unchanged`. Roughly
  half of all page modules are slide pages with no video, so without this a
  healthy run and a completely broken extractor produced identical summaries.

### Fixed

- **n8n — page modules were invisible to ingestion**: a page's `index.html` is
  reported by `core_course_get_contents` with `mimetype: NULL`, so the
  `SUPPORTED_MIMES` filter silently skipped every page module and no spoken
  course content had ever reached the index. Detection now keys on `modname`.
  Slide PDFs attached to those same page modules are unaffected and continue
  to ingest.
- **n8n — a page that loses its video no longer loops forever**: when a page
  whose transcript had been ingested is edited to remove the embed, the old
  document is deleted and the stale state entry is now dropped with it.
  Previously the entry kept the old `timemodified`, so every later run
  re-classified the page as updated, re-issued the delete and skipped again,
  never converging — and reported `error: null` while a document was being
  removed.

### Known limitations

- Transcripts are YouTube's automatic captions, not human-authored subtitles.
  Proper nouns are frequently mis-transcribed: in the Psicologia generale
  corpus the neuropsychology case *Phineas Gage* comes through as *"Finess
  Cage"*, so a student searching the correct spelling will not retrieve that
  passage. Each document carries a provenance header stating its origin. See
  `n8n/README.md`.
- The `ytdlp-api` service refuses to enable its YouTube provider unless a
  cookie path is configured, even though public-video transcripts need no
  authentication. `n8n/README.md` documents the placeholder file that
  satisfies the check.

## [0.6.0] - 2026-07-18

Diagnostics and inline citations. Pairs with
[vektra-stack v0.6.0](https://github.com/vektralabs/vektra-stack), which
ships the backend half of the citations feature (FEAT-021), the same way
v0.5.0 paired for FEAT-014.

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
