# n8n Moodle-to-Vektra Automatic Ingestion

Automated pipeline that syncs Moodle course materials (PDF, DOCX, PPTX, Markdown) into Vektra via the ingestion API. Uses n8n as the orchestrator, polling Moodle Web Services for new, updated, or removed files.

## How It Works

```
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
6. Maps Moodle course shortname to Vektra namespace (1:1)

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

### Step 4: Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your values:

| Variable | Description |
|----------|-------------|
| `N8N_PORT` | n8n UI port (default: 5678) |
| `N8N_ENCRYPTION_KEY` | Random string for n8n credential encryption |
| `MOODLE_URL` | Moodle base URL (must match `$CFG->wwwroot`, default: `http://vektra-moodle`) |
| `MOODLE_WS_TOKEN` | Token from Step 2 |
| `VEKTRA_API_URL` | Vektra API URL (Docker: `http://vektra-stack-vektra-1:8000`, host: `http://localhost:8000`) |
| `VEKTRA_API_KEY` | API key from Step 3 |
| `INGEST_CRON` | Cron expression (default: `*/5 * * * *`) |

### Step 5: Start n8n

```bash
docker compose up -d
```

### Step 6: Connect Docker Networks

n8n needs to reach both the Vektra API and Moodle containers. Connect it to their networks:

```bash
# Connect to vektra-stack network (for Vektra API)
docker network connect vektra-stack_default n8n-n8n-1

# Connect to vektra-moodle network (for Moodle)
docker network connect docker_default n8n-n8n-1
```

> **Note**: Container and network names may vary. Use `docker network ls` and `docker ps` to verify.

### Step 7: Import Workflow

1. Open n8n at `http://localhost:5678`
2. Complete the initial setup (create owner account)
3. Go to **Workflows** > **Add workflow** > **Import from file**
4. Select `workflows/moodle-ingest.json`

### Step 8: Activate and Test

1. Click **Execute workflow** to run a manual test
2. Check the **Ingestion Summary** node output
3. Toggle the workflow to **Active** (click **Publish**) for scheduled execution

> **Note**: The workflow reads configuration from Docker container environment variables (set in `.env`), not from n8n's built-in Variables feature.

## Testing

1. Upload a PDF file to a Moodle course as a **File** resource
2. Trigger the workflow manually (click **Execute Workflow** in n8n)
3. Check the **Ingestion Summary** node output
4. Verify the document exists in Vektra via the API:
   ```bash
   curl http://localhost:8000/api/v1/documents?namespace=<course-shortname> \
     -H "Authorization: Bearer <api-key>"
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

```
127.0.0.1 vektra-moodle
```

The Moodle container maps port 80 to the host (`127.0.0.1:80:80` in docker-compose), so `http://vektra-moodle` resolves to the local Moodle instance from both Docker and the browser.

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

A file with the same name but different content already exists in the namespace. The workflow prefixes filenames with the Moodle module ID (`mod123_filename.pdf`) to avoid this. If it still occurs, clear the workflow static data (Settings > Static Data > Clear) and re-run.

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
