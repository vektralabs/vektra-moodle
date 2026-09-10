# n8n Moodle-to-Vektra Automatic Ingestion

Automated pipeline that syncs Moodle course materials (PDF, DOCX, PPTX, Markdown) into Vektra via the ingestion API. Uses n8n as the orchestrator, polling Moodle Web Services for new, updated, or removed files.

## How It Works

```text
Moodle (LMS)
  |  polling via Web Services REST API
  v
n8n (orchestrator)
  |  POST /api/v1/ingest  (new/updated files)
  |  DELETE /api/v1/documents/batch  (removed files)
  v
Vektra API (vektra-stack)
```

The workflow runs on a configurable schedule (default: every 5 minutes) and:

1. Fetches all courses from Moodle
2. For each course, retrieves file resources from all sections
3. Compares against stored state to detect new, updated, and removed files
4. Downloads new/updated files and ingests them into Vektra
5. Deletes documents from Vektra when files are removed from Moodle
6. Maps Moodle course shortname to Vektra namespace via slugification (see [Namespace Convention](#namespace-convention))

## Prerequisites

- Running **vektra-stack** Docker stack (Vektra API at port 8000)
- Running **vektra-moodle** Docker stack (Moodle LMS)
- Docker and Docker Compose installed

## Setup

### Step 1: Enable Moodle Web Services

1. Log into Moodle as admin
2. Go to **Site administration > Advanced features** > Enable **Web services**
3. Go to **Site administration > Plugins > Web services > Manage protocols** > Enable **REST protocol**
4. Go to **Site administration > Plugins > Web services > External services** > **Add**
   - Name: `n8n Ingestion`
   - Enabled: Yes
   - Authorized users only: Yes
5. Click **Edit** on the new service and enable:
   - **Can download files**: Yes
   - **Can upload files**: Yes
6. Click the **Functions** link on the new service and add:
   - `core_course_get_courses`
   - `core_course_get_contents`
   - `core_webservice_get_site_info`
7. Click **Authorized users** and add the admin user (or a dedicated service user)

### Step 2: Create Moodle WS Token

1. Go to **Site administration > Plugins > Web services > Manage tokens**
2. Click **Create token**
   - User: select the authorized user
   - Service: `n8n Ingestion`
3. Copy the generated token

### Step 3: Create Vektra API Key

```bash
curl -X POST http://localhost:8000/api/v1/api-keys \
  -H "Authorization: Bearer <admin-key>" \
  -H "Content-Type: application/json" \
  -d '{"label": "n8n-moodle-sync", "scopes": ["ingest", "admin"]}'
```

Save the `key` from the response (shown only once). The `admin` scope is needed for document deletion.

> **Security warning**: the `admin` scope grants the full admin surface — managing API keys, namespaces, and admin endpoints — far more than the `DELETE /api/v1/documents/batch` call this workflow needs. Treat this key like a root credential:
>
> - store it only in `n8n/.env` (never commit; the file is gitignored)
> - rotate it on personnel changes or suspected leak (delete + recreate)
> - if the Vektra backend ever ships a narrower delete-only scope, switch to two keys: ingest-only for uploads + the narrower scope for deletions
>
> Tracked as BUG-010 in `.s2s/BACKLOG.md`.

### Step 4: Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your values:

| Variable | Description |
|----------|-------------|
| `N8N_PORT` | n8n UI port (default: 5678) |
| `N8N_ENCRYPTION_KEY` | Random string for n8n credential encryption |
| `MOODLE_URL` | Moodle base URL (must match `$CFG->wwwroot`, default: `http://vektra-moodle`). With the n8n stack the Moodle compose file's `MOODLE_URL` must also be set to `http://vektra-moodle` (see [Hosts file configuration](#hosts-file-configuration)) |
| `MOODLE_WS_TOKEN` | Token from Step 2 |
| `VEKTRA_API_URL` | Vektra API URL (Docker: `http://vektra-stack-vektra-1:8000`, host: `http://localhost:8000`) |
| `VEKTRA_API_KEY` | API key from Step 3 |
| `INGEST_CRON` | Cron expression (default: `"*/5 * * * *"` — quote to keep dotenv parsers from splitting on whitespace) |
| `DELETION_SAFETY_THRESHOLD` | Skip mass deletions when Moodle returns an empty file list for a course that previously had >= N stored documents (default 3, set to 0 to disable) |
| `VEKTRA_STACK_NETWORK` | Override the external Docker network name for vektra-stack (default `vektra-stack_default`) |
| `MOODLE_NETWORK` | Override the external Docker network name for vektra-moodle (default `docker_default`) |

### Step 5: Start n8n

```bash
docker compose up -d
```

n8n joins the `vektra-stack_default` and `docker_default` networks automatically
(declared as `external` in `docker-compose.yml`). Make sure both vektra-stack
and vektra-moodle are running first, otherwise startup will fail.

> **Note**: If your `vektra-stack` or `vektra-moodle` compose files use project
> names that produce different network names (e.g. due to a non-default
> directory layout), set `VEKTRA_STACK_NETWORK` and/or `MOODLE_NETWORK` in
> `n8n/.env` to match. Check with `docker network ls`.

### Step 6: Import Workflow

1. Open n8n at `http://localhost:5678`
2. Complete the initial setup (create owner account)
3. Go to **Workflows** > **Add workflow** > **Import from file**
4. Select `workflows/moodle-ingest.json`

### Step 7: Activate and Test

1. Click **Execute workflow** to run a manual test
2. Check the **Ingestion Summary** node output
3. Toggle the workflow to **Active** (click **Publish**) for scheduled execution

> **Note**: The workflow reads configuration from Docker container environment variables (set in `.env`), not from n8n's built-in Variables feature.

> **Upgrading from n8n 1.x**: if you previously activated this workflow on
> n8n 1.x and upgraded the container to 2.x, the schedule trigger will not
> fire until the workflow is explicitly published under the new state model.
> n8n 2.x removed the `n8n publish:workflow` CLI subcommand; use one of:
>
> - **UI** (simplest): open the workflow in the n8n editor and click **Publish**.
> - **REST API** (programmatic):
>   ```bash
>   curl --request="PATCH" "http://localhost:5678/api/v1/workflows/<workflow-id>/activate" \
>     --header="X-N8N-API-KEY: <your-n8n-api-key>"
>   ```
>   The API key is created under **Settings > n8n API > Create an API key** in the n8n UI.
>
> Fresh installs importing the JSON via the UI are not affected.

## Testing

The Code nodes' logic can be checked without Moodle, Vektra or n8n:

```bash
node n8n/tests/scope-by-block.mjs
```

The probes read each node's source out of the workflow template and run it with
n8n's globals stubbed, so they check the template itself rather than a copy of
it. They cover the course scoping, the deletion diff, file extraction and the
summary counts.

For a run against the real stack:

1. Upload a PDF file to a Moodle course as a **File** resource
2. Trigger the workflow manually (click **Execute Workflow** in n8n)
3. Check the **Ingestion Summary** node output
4. Verify the document exists in Vektra via the API:
   ```bash
   curl http://localhost:8000/api/v1/documents?namespace=<course-slug> \
     -H "Authorization: Bearer <api-key>"
   # <course-slug> is the slugified shortname — see Namespace Convention
   ```
5. **Test update**: Re-upload a modified version of the same file, trigger again
6. **Test deletion**: Remove the file from Moodle, trigger again

### Local Test Environment

| Service | URL | Credentials |
|---------|-----|-------------|
| Moodle | `http://vektra-moodle` | admin / Admin123! |
| Vektra API | `http://localhost:8000` | — |
| n8n | `http://localhost:5678` | Set during first access |

#### Hosts file configuration

Moodle's `$CFG->wwwroot` (the base URL in `config.php`) must be a single hostname that works both for **n8n inside Docker** and for the **browser on the host machine**. Moodle uses this URL to generate all internal links, CSS paths, and file download URLs (`pluginfile.php`).

The hostname `vektra-moodle` is the Docker container name. n8n reaches it via Docker networking. The browser on the host doesn't know this name, so you need to add it to the hosts file:

- **Windows**: edit `C:\Windows\System32\drivers\etc\hosts` (as Administrator)
- **macOS/Linux**: edit `/etc/hosts`

Add this line:

```text
127.0.0.1 vektra-moodle
```

The Moodle container is bound to the host as `127.0.0.1:${MOODLE_PORT:-10180}:80` (default port `10180`). For the browser to resolve `http://vektra-moodle` to that bound port, also add `MOODLE_PORT=80` to `docker/.env` (or use a per-host hostname trick) — alternatively, browse Moodle via `http://localhost:10180` and reserve `vektra-moodle` for n8n's container-to-container traffic. n8n inside Docker uses the container DNS (port 80) regardless of the host binding.

## Configuration

### Polling Interval

Change `INGEST_CRON` in `.env` and restart n8n. Examples:
- `*/5 * * * *` — every 5 minutes (default)
- `*/30 * * * *` — every 30 minutes
- `0 * * * *` — every hour
- `0 2 * * *` — daily at 2 AM

### Supported File Types

The workflow only processes files uploaded as **File resources** (`mod_resource`) in Moodle. Files embedded inline in Page activities (`mod_page`), labels, or other content types are **not** detected.

To upload files correctly: in the course, click **Add an activity or resource** → **File**.

Supported MIME types:
- PDF (`application/pdf`)
- DOCX (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
- PPTX (`application/vnd.openxmlformats-officedocument.presentationml.presentation`)
- Markdown (`text/markdown`)

### Namespace Convention

The workflow derives the Vektra namespace from the Moodle course shortname by applying a fixed slugification algorithm. The Moodle block plugin applies the same algorithm when querying Vektra, so both sides always target the same namespace.

**Algorithm** (applied to the raw `shortname` field):
1. NFD decompose + strip combining marks (accent removal: `è` → `e`, `ñ` → `n`)
2. Lowercase
3. Replace any character outside `[0-9a-z_-]` with `-`
4. Collapse consecutive `-`
5. Trim leading/trailing `-`
6. Truncate to 50 characters

**Examples**:

| Shortname | Namespace |
|-----------|-----------|
| `psicologia-generale` | `psicologia-generale` |
| `Course 101` | `course-101` |
| `Física Cuántica` | `fisica-cuantica` |
| `Diritto: intro` | `diritto-intro` |

**Important**: the explicit `course_id` and `namespace` overrides on the block settings are used as-is (no slugification). Only the shortname fallback is slugified. If a course shortname produces an unexpected namespace slug, set an explicit `course_id` override in the block settings.

## Running more than one Moodle

One n8n can drive several Moodle instances from the same pipeline. There is a
single workflow template in this repository, `n8n/workflows/moodle-ingest.json`,
and no per-instance copies: two copies of the same pipeline drift, which is how
`mooc.unical.it` ended up months behind, missing three features and hardcoding a
state path.

Each instance is one small file under `n8n/instances/`, and the JSON to import is
generated from the template:

```bash
node n8n/scripts/build-instance.mjs mooc > /tmp/mooc.json
# The container mounts only n8n_data at /home/node/.n8n, so the host's /tmp is
# not visible inside it. Copy the file in, or the import fails on a missing path.
docker compose cp /tmp/mooc.json n8n:/tmp/mooc.json
docker compose exec -T n8n n8n import:workflow --input=/tmp/mooc.json
docker compose exec -T n8n n8n publish:workflow --id=<id from the instance file>
docker compose restart n8n
```

Importing over an active workflow **deactivates it** — the publish step is not
optional, and n8n needs restarting for the change to take effect.

The generated workflows are identical except one node, `Config`, which reads the
instance's variables. Adding an instance means adding its file and its variables,
never editing the pipeline.

### Why the variables are prefixed

n8n environment variables are per process, so two workflows in one n8n cannot
read different values from the same name. The main instance uses `MOODLE_URL`,
`MOODLE_WS_TOKEN` and `STATE_FILE_PATH`; a second instance uses the same names
with a prefix, for example `MOOC_MOODLE_URL`. `INGEST_OPT_OUT_TAG` is
deliberately not prefixed: the tag is a convention taught to teachers and should
read the same across every Moodle of the same university.

### The state path is the dangerous one

`Config` refuses to start without a state path rather than falling back to a
default. That is deliberate. Two workflows sharing one state file each see the
other instance's documents as present in state but absent from Moodle, classify
them `removed`, and delete them from the index — then re-ingest them on the next
run, in a loop. The empty-course guard never catches it, because neither file
list is ever empty.

So each instance's state path must be distinct, and on an existing instance it
must point at the file that instance **already uses**. Pointing it somewhere new
is not an error the pipeline can detect: it simply looks like an empty index and
re-ingests everything.

## Which courses get indexed

A course is indexed while — and only while — it carries the **Vektra block**.
Adding the block to a course puts its material in the index on the next run;
removing the block takes the material back out. Nobody has to touch n8n, and no
list of courses is maintained anywhere.

Without this the pipeline indexed every course on the Moodle. On a university
installation that is the whole institution, which is neither wanted nor
affordable.

### What the pipeline does with each course

| Course | What happens | Cost per run |
|---|---|---|
| Has the block | Indexed, as before | block lookup + contents |
| No block, never indexed | Skipped entirely | block lookup |
| No block, but in the index | **Removed from the index** | block lookup, once |
| Block lookup failed | Left exactly as it is | block lookup |

Removal is deliberate and it is not free to skip: leaving an unscoped course
alone would freeze a stale index that keeps answering students from material the
teacher meant to withdraw. So the course goes through the normal deletion path,
its documents are deleted from Vektra, and its state entries are dropped — after
which it costs nothing but the lookup, forever.

> **Before the first scoped run**, every course that should stay in the index
> must already have the block. Any indexed course without one is removed on that
> run.

### Enabling the web-service function

The pipeline calls `core_block_get_course_blocks`, which is **not** part of the
default `n8n Ingestion` service. Add it under **Site administration > Server >
Web services > External services > n8n Ingestion > Functions**.

The service's numeric id differs between installations — it is 2 on one of ours
and 3 on the other — so navigate by name, not by a remembered id.

Until the function is enabled, every lookup fails and the workflow stops with
`Scope Courses: every block lookup failed`. That is deliberate: with no usable
signal the pipeline would index nothing and delete everything it had.

### Put the block on the course, never on the site

Moodle reports a block placed on the site (or on a category) with *show in
subcontexts* under **every** course beneath it. Left unhandled, one such block
would scope the entire Moodle back in, silently.

The workflow recognises it: an inherited block is one instance seen under many
courses, while a real per-course block is unique to its course, so any instance
id appearing under more than one course is ignored and logged. If the *only*
Vektra blocks found are inherited ones and indexed courses would be removed, the
run stops rather than prune.

**This test has a limit, and it is not closeable from here.** An inherited block
is recognised by being seen under more than one course, so a block on a category
holding exactly **one** course looks identical to that course's own block, and
the course is indexed. A failed lookup elsewhere can push a genuinely inherited
block down to a single sighting too. `core_block_get_course_blocks` returns
`instanceid`, `name`, `region`, `positionid`, `collapsible`, `dockable`,
`weight` and `visible` — no parent context — so nothing in the response settles
it. The blast radius is one course indexed that should not be, against the whole
installation the test does catch; DEBT-011 carries the proper fix, which needs
the plugin to report block ownership itself.

What the workflow can do, and does, is **name the courses where the doubt
applies**. A course alone in its category is precisely where a single sighting
stops being evidence, so each one is logged by name:

```
[Scope Courses] course 12 (analisi-2) is the only course in its category.
If its Vektra block sits on the category rather than on the course, it is
being indexed by mistake — check where the block is.
```

The course is still indexed — refusing it would break every course that is
legitimately alone in its category — but the doubt is now a line in the run log
with a course number on it, which takes a moment to settle, instead of a
paragraph in this file that nobody reads at the right time.

So the operational rule is not merely tidiness: **put the block on the course**.

### A failed lookup never removes anything

Moodle answers a web-service exception with HTTP 200 and an `exception` body, so
a failure and "this course has no block" look alike unless they are told apart.
They are: a course whose lookup failed is left out of the run entirely, which
leaves its index untouched. This matters because on `mooc.unical.it` the
web-service token belongs to a person's account rather than a service account —
if that account is disabled, every lookup fails at once.

## Hidden modules

A module hidden in Moodle (the eye icon, `visible = 0`) is still ingested, but
the document is tagged so the backend can use its content without exposing the
source. The workflow sends `hidden_from_students: true` in the ingest request's
`metadata` field; for a visible module the key is omitted, which the backend
reads as false.

Hiding is deliberately not the same as opting out. An opted-out module leaves
the index entirely (see above); a hidden one stays searchable but should not be
cited back to students. Use the `[no-ai]` tag when the material must not be used
at all.

Two things make this work that are easy to get wrong:

- **Visibility is part of change detection.** Hiding a module does not touch any
  file, so `timemodified` alone would classify the flip as unchanged and the
  document would keep its stale flag forever. The stored state records the
  visibility each document was ingested with, and a difference marks the file as
  updated.
- **The old document is deleted first.** Re-ingesting unchanged content returns
  `exists` and ignores the metadata of that request, so without the delete the
  flag would never change. The `updated` path already deletes before uploading,
  which is what makes the flip take effect.

Modules that are visible but carry availability restrictions (group, date) are
reported as visible. Restriction-aware visibility is not covered.

## Excluding material from the index

A teacher can keep a module out of the AI index by putting a tag in its
**title**. The default tag is `[no-ai]`, configurable with `INGEST_OPT_OUT_TAG`:

```
Lezione 3 - Percezione [no-ai]
[NO-AI] Bozza non revisionata
```

The match is case-insensitive and looks anywhere in the title, because the
title is typed by a person. Setting `INGEST_OPT_OUT_TAG=` (empty) disables the
feature.

It is the **module title**, not the file name. Tagging a module excludes
everything it contributes: its documents and, for a page, its video transcript.

Removal is not just a skip. A module tagged after it was already ingested is
deleted from the index on the next run, and untagging it puts it back on the
run after that. The ingestion summary reports the count as `opted out`, so a
tag that is working is distinguishable from an extractor that is broken.

One interaction worth knowing: the pipeline suppresses all deletions for a
course when Moodle returns an empty file list, so an outage cannot wipe the
index. Tagging every module in a course also produces an empty list, and that
case is deliberately exempt from the safety net — otherwise a course-wide
opt-out would be silently ignored.

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

## Troubleshooting

### "Invalid token" from Moodle

- Verify the token is correct and not expired
- Check the WS user has the required capabilities
- Ensure the external service includes all required functions

### n8n cannot reach Moodle or Vektra

- Verify Docker network connections: `docker network inspect vektra-stack_default`
- Check container names match your setup (`docker ps`)
- Test connectivity: `docker exec n8n-n8n-1 sh -c "echo quit | nc vektra-moodle 80"`

### "Access control exception" on file download

- Ensure the external service has **Can download files** enabled (Edit service > tick the checkbox)
- `MOODLE_URL` must match Moodle's `$CFG->wwwroot` exactly. Both should use the Docker container name (e.g., `http://vektra-moodle`)

### 409 Conflict on ingest

A file with the same name but different content already exists in the namespace. The workflow prefixes filenames with the Moodle module ID (`mod123_filename.pdf`) to avoid this. If it still occurs, the workflow's view of what is already ingested has likely drifted from Vektra; reset the JSON state file at `STATE_FILE_PATH` (default `/home/node/.n8n/moodle-ingest-state.json`) and re-run — see [Force re-processing of all files](#force-re-processing-of-all-files) below.

### Force re-processing of all files

The workflow tracks ingested files in a JSON state file. To force re-processing:

```bash
docker exec n8n-n8n-1 sh -c 'rm -f /home/node/.n8n/moodle-ingest-state.json'
```

On the next run, all files will be re-downloaded and re-uploaded. Vektra will recognize already-ingested files (status `exists`) and not re-process them.

## Deployment to Production

Follow the same steps, adjusting URLs to match your production environment:

- `MOODLE_URL`: your production Moodle URL (Docker network name or hostname)
- `VEKTRA_API_URL`: your production Vektra API URL
- Generate new tokens and API keys for production
- Consider a longer polling interval for production (e.g., every 30 minutes)

### HTTPS deployments

If Moodle is served over HTTPS (see `docker/README.md` — HTTPS deployment), set `MOODLE_URL` to the full HTTPS URL matching `$CFG->wwwroot`:

```env
MOODLE_URL=https://your-moodle.example.com
```

The workflow's `httpReq` helper supports both HTTP and HTTPS — it selects the correct module at runtime based on the URL scheme. No additional configuration is required.

> **Important**: `MOODLE_URL` must match `$CFG->wwwroot` exactly. Moodle generates all file download URLs using `wwwroot` as the base, so a mismatch (e.g. HTTP `MOODLE_URL` with HTTPS `wwwroot`) will cause every file download to fail silently.
