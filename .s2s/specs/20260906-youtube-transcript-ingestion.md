# Spec: YouTube transcript ingestion for the n8n Moodle pipeline

**ID**: FEAT-004 | **Status**: draft | **Created**: 2026-09-06
**Branch**: `feat/youtube-transcript-ingestion`
**Component**: `n8n/` (ingestion stack) — no changes to the PHP plugin or to vektra-stack

---

## Traceability

- **Originated from**: session request — "estrarre la trascrizione se si tratta di materiale YouTube"
- **Depends on**: [`fvadicamo/yt-dlp-api`](https://github.com/fvadicamo/yt-dlp-api) v0.2.4+ (MIT), image `ghcr.io/fvadicamo/yt-dlp-api`
- **Related**: BUG-001 (namespace slug alignment) — the slug algorithm in `Extract Files` is reused unchanged

---

## Problem

Course video material is invisible to Vektra. Lecture videos are embedded as
`<iframe src="https://www.youtube.com/embed/{id}">` inside `mod_page` HTML, and
the ingestion workflow only collects `contents` entries whose `mimetype` is one
of four document types. Page modules never match, so no spoken course content
has ever reached the RAG index.

For the Psicologia generale course this is 36 lectures and roughly 91k words of
material — comparable in volume to the PDF corpus already ingested.

---

## Verified findings

Everything below was measured against the live dev stack and the real course
data on 2026-09-06, not inferred.

### Moodle side

`core_course_get_contents` on course 3 returns, for each `page` module:

```
contents: 1 element
  [0] type=file  filename=index.html  mimetype=NULL  filesize=0
      timemodified=1776408921
      fileurl=.../webservice/pluginfile.php/145/mod_page/content/index.html?forcedownload=1
```

- **`mimetype` is `NULL`**, not `text/html`. Detection MUST key on
  `mod.modname === 'page'`. Adding `'text/html'` to `SUPPORTED_MIMES` would
  match nothing and fail silently, which is the current bug.
- **`filesize` is `0`** and cannot be used for change detection.
- **`timemodified` is present and valid** — dedup keys unchanged.
- Fetching `fileurl` with a WS token returns HTTP 200,
  `content-type: text/html;charset=UTF-8`. Sample body: 333 bytes.

### Video/page ratio

Counted directly in `mdl_page` across all 71 page modules:

| Videos per page | Modules |
|---|---|
| 0 | 35 |
| 1 | 36 |
| >1 | **0** |

The mapping is strictly 1:1. The state-file schema
(`state.courseFiles[namespace][fileurl]`) therefore needs **no change**, and
`Dedup & Diff` / `Handle Deletions` are untouched. Pages without a video are
roughly half the modules, so the "no video found" branch is a normal path, not
an edge case.

### PDF attachments live on page modules

Slide PDFs are **not** separate `resource` modules. They are attached to `page`
modules alongside `index.html`:

| Page shape | Count |
|---|---|
| `index.html` only — video lecture | 36 |
| `index.html` + one `application/pdf` — slides | 35 |
| both video and PDF | 0 |
| neither | 0 |

**Consequence, and the most dangerous constraint in this change**: collecting a
page's `index.html` MUST NOT short-circuit the existing per-content loop. A
`continue` after handling the page module would drop all 35 attached PDFs from
ingestion — a silent regression of behaviour that works today. Both the
`index.html` transcript candidate and the normal `SUPPORTED_MIMES` scan must run
for the same module.

### Transcript availability

All 36 video IDs resolve to the channel *"Università della Calabria - Campus di
Arcavacata"*. All 36 expose exactly one caption track: `it`, `kind=asr`
(auto-generated). There are no manually authored tracks.

### Fetch method

Two independent methods were tested end to end:

| Method | Result |
|---|---|
| Watch-page scrape → `timedtext` baseUrl | **Fails.** Empty body on every format. |
| InnerTube `/youtubei/v1/player`, iOS client spoof | Works: 36/36, 91,240 words |
| `yt-dlp-api` `GET /api/v1/transcript` | Works: 36/36, 91,387 words, 89 s total (~2.5 s/video) |

The two working methods agree within 0.2%, confirming they extract the same
content. The naive method broke immediately, which is the argument against
hand-rolling: the working path depends on a spoofed `clientVersion` and a
public API key, exactly the strings that rot.

`yt-dlp-api` was verified running image `:latest` (v0.2.4, bundling yt-dlp
2026.07.04). Health reports `ytdlp`, `ffmpeg`, `nodejs`, `storage` and
`youtube_connectivity` all healthy.

### Cookie gate (setup trap)

The README states that transcripts of public videos often work without cookies.
This is true of the *fetch*, but startup validation disables the entire YouTube
provider when no cookie path is configured (`app/main.py:211-212`), and requests
then fail with `INVALID_URL: No provider available for URL`.

A minimal Netscape file with a single non-authenticating `PREF` cookie satisfies
the check, after which all 36 transcripts fetch successfully. **This is a
workaround for an upstream quirk and must be documented as such**, with an issue
opened on `fvadicamo/yt-dlp-api` so transcript-only deployments do not need it.

---

## Requirements

### Functional

- **FR-1** — The workflow SHALL collect, from each `page` module in
  `core_course_get_contents`, the `contents` entry whose `filename` is
  `index.html`, as a transcript candidate. Page modules are identified by
  `modname === 'page'`, never by `mimetype`, which is `NULL`.
- **FR-1b** — Collecting a page's `index.html` SHALL NOT prevent the existing
  `SUPPORTED_MIMES` scan from running over that same module's remaining
  `contents`. The 35 slide PDFs attached to page modules MUST continue to be
  ingested exactly as today.
- **FR-2** — For each collected page, the workflow SHALL download the module's
  `index.html` through the existing Moodle WS download path.
- **FR-3** — The workflow SHALL extract YouTube video IDs from the HTML,
  matching both `youtube.com/embed/{id}` and `youtube-nocookie.com/embed/{id}`.
- **FR-4** — When no video ID is found, the page SHALL be reported with status
  `skipped` and SHALL NOT be ingested. Page text is out of scope.
- **FR-5** — For a page with a video, the workflow SHALL request the transcript
  from `GET {YTDLP_API_URL}/api/v1/transcript` with `lang=it`, `source=auto`,
  `fmt=text`, authenticating with `X-API-Key`.
- **FR-6** — The transcript text SHALL be normalized to remove the hard line
  wraps inherited from the VTT source before it is packaged.
- **FR-7** — The normalized transcript SHALL be packaged as a Markdown document
  carrying the lecture title and source URL, and uploaded through the existing
  `POST /api/v1/ingest` multipart path. The document is built in memory; no file
  is written to disk.
- **FR-8** — A transcript fetch failure SHALL produce a `failed` result for that
  page carrying the upstream error code, and SHALL NOT abort the course run.
- **FR-9** — Re-running the workflow with unchanged `timemodified` SHALL NOT
  re-ingest a transcript.

### Non-functional

- **NFR-1** — `NODE_FUNCTION_ALLOW_EXTERNAL` SHALL remain empty. The workflow
  reaches the service over HTTP, as it already does for Moodle and Vektra.
- **NFR-2** — The service SHALL be pinned to the `:weekly` tag so yt-dlp
  tracking is inherited rather than maintained here.
- **NFR-3** — The API key SHALL be supplied via environment, never committed.

---

## Design

| Node / file | Change |
|---|---|
| `Extract Files` | Emit an extra candidate per page module: `_kind:'page'`, `fileurl` = `index.html` URL, `timemodified` from that entry. The existing `SUPPORTED_MIMES` loop still runs over the same module — no `continue` — so attached PDFs keep being ingested. |
| `Dedup & Diff` | **None.** Key stays `fileurl`. |
| `Handle Deletions` | **None.** |
| `Process Single File` | New branch on `_kind === 'page'`: download HTML (existing helper), regex for video ID, `skipped` if none, else fetch transcript, normalize, build `.md` buffer, continue into the existing multipart upload and state write. |
| `Merge Course Results` / `Ingestion Summary` | Surface `skipped` alongside existing statuses. |
| `n8n/docker-compose.yml` | New `ytdlp-api` service on `ghcr.io/fvadicamo/yt-dlp-api:weekly`, cookie file mounted read-only. |
| `n8n/.env.example` | `YTDLP_API_URL`, `YTDLP_API_KEY`, cookie path. |
| `n8n/README.md` | Service setup, the cookie-gate trap, the ASR quality limitation. |

Document shape:

```markdown
# {module name}

Source: https://www.youtube.com/watch?v={id}
Transcript: auto-generated captions (it), retrieved {date}

{normalized transcript text}
```

The provenance header is deliberate: the transcript is machine-generated and a
reader who lands on a citation should see that without leaving the document.

---

## Non-goals

- Ingesting the text of pages that contain no video.
- ASR/Whisper transcription for videos lacking captions. Coverage is 100%, so
  the fallback has no user today.
- Supporting `mod_url`, labels, or section summaries as video sources. The
  course data contains none; revisit if that changes.
- Any change to vektra-stack or to the PHP plugin.

---

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **ASR quality.** Auto-captions mis-transcribe proper nouns. Observed: *"Finess Cage"* for **Phineas Gage**, the most-cited case in the lecture. A student searching the correct spelling will not retrieve the passage. | High | Documented in README and in the document header. Not mitigated technically in this change — see Open questions. |
| Upstream YouTube changes break extraction | Medium | Delegated to yt-dlp via the `:weekly` tag. Failure is loud (non-2xx) and isolated per page. |
| Cookie gate misleads operators | Low | Documented; upstream issue to be opened. |
| Service unavailable during a run | Low | Per-page `failed` result, course run continues — same shape as the existing Moodle-download failure path. |

---

## Acceptance criteria

- [ ] A course run ingests 36 transcript documents from the Psicologia generale course
- [ ] The 35 video-less pages are reported `skipped`, not `failed`
- [ ] The 35 slide PDFs attached to page modules are still ingested (no regression)
- [ ] No page produces more than one transcript document
- [ ] A second run with no content change ingests nothing
- [ ] Editing a page's content causes exactly that transcript to be re-ingested, with the old document deleted first
- [ ] With the service stopped, the run completes and reports the affected pages as `failed` with a readable error
- [ ] Ingested text contains no hard line wraps mid-sentence
- [ ] `NODE_FUNCTION_ALLOW_EXTERNAL` is still empty
- [ ] `n8n/README.md` documents the cookie requirement and the ASR limitation

---

## Testing approach

The workflow has no automated test harness; verification is manual against the
`docker/` dev stack, which already holds the real course data (course 3, 71
pages, 36 with video).

1. Baseline: record current state file and Vektra document count.
2. Full run; assert the counts in the acceptance criteria.
3. Idempotence: immediate second run ingests nothing.
4. Update: edit one page, re-run, assert single re-ingestion and old-document delete.
5. Failure: stop `ytdlp-api`, re-run, assert graceful per-page failure.
6. Spot-check one ingested document for provenance header and clean text.

---

## Open questions

1. **ASR quality mitigation.** Options: accept as-is; post-process with an LLM
   correction pass; supply a course glossary of proper nouns. Deferred — it
   does not block this change, but it affects whether citations are trustworthy.
2. **Language.** `lang=it` is hardcoded to match the corpus. Whether to derive
   it from the block's per-course language setting is undecided.
