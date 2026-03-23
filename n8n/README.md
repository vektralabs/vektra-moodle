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

1. Fetches all courses from Moodle (or a filtered subset)
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
5. Click the **Functions** link on the new service and add:
   - `core_course_get_courses`
   - `core_course_get_contents`
   - `core_webservice_get_site_info`
6. Click **Authorized users** and add the admin user (or a dedicated service user)

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
| `MOODLE_URL` | Moodle base URL (Docker: `http://vektra-moodle`, host: `http://localhost:10180`) |
| `MOODLE_WS_TOKEN` | Token from Step 2 |
| `VEKTRA_API_URL` | Vektra API URL (Docker: `http://vektra-stack-vektra-1:8000`, host: `http://localhost:8000`) |
| `VEKTRA_API_KEY` | API key from Step 3 |
| `INGEST_CRON` | Cron expression (default: `*/5 * * * *`) |
| `MOODLE_COURSE_IDS` | Optional comma-separated course IDs to filter (empty = all) |

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

### Step 8: Configure Credentials in n8n

1. In the imported workflow, click on any **POST /ingest** or **DELETE** node
2. Under **Credential for Header Auth**, click **Create New Credential**
   - Name: `Vektra Auth`
   - Header Name: `Authorization`
   - Header Value: `Bearer <your-vektra-api-key>`
3. Save and assign this credential to all HTTP nodes that use it

### Step 9: Set n8n Environment Variables

In the n8n UI, go to **Settings > Variables** and create:

| Variable | Value |
|----------|-------|
| `MOODLE_URL` | `http://vektra-moodle` (or your Moodle URL) |
| `MOODLE_WS_TOKEN` | Your Moodle WS token |
| `VEKTRA_API_URL` | `http://vektra-stack-vektra-1:8000` (or your Vektra URL) |
| `INGEST_CRON` | `*/5 * * * *` (or your preferred schedule) |
| `MOODLE_COURSE_IDS` | Empty (all courses) or comma-separated IDs (e.g., `6,7,8`) |

### Step 10: Activate Workflow

Toggle the workflow to **Active** in the n8n UI.

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
| Moodle | `http://localhost:10180` | admin / Admin123! |
| Vektra API | `http://localhost:8000` | — |
| n8n | `http://localhost:5678` | Set during first access |
| Moodle courses | IDs: 6, 7, 8 | — |

## Configuration

### Polling Interval

Change `INGEST_CRON` in n8n variables. Examples:
- `*/5 * * * *` — every 5 minutes (default)
- `*/30 * * * *` — every 30 minutes
- `0 * * * *` — every hour
- `0 2 * * *` — daily at 2 AM

### Course Filtering

Set `MOODLE_COURSE_IDS` to a comma-separated list of Moodle course IDs (e.g., `6,7,8`). Leave empty to sync all courses.

### Supported File Types

The workflow only processes files with these MIME types:
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
- Try `docker exec n8n-n8n-1 wget -qO- http://vektra-moodle` to test connectivity
- Check container names match your setup (`docker ps`)

### 409 Conflict on ingest

A file with the same name but different content already exists in the namespace. The workflow prefixes filenames with the Moodle module ID (`mod123_filename.pdf`) to avoid this. If it still occurs, clear the workflow static data (Settings > Static Data > Clear) and re-run.

### Workflow static data reset

To force re-processing of all files, clear the workflow static data:
1. Open the workflow in n8n
2. Go to **Settings** (gear icon) > **Static Data**
3. Clear the data and save

## Deployment to Production

Follow the same steps, adjusting URLs to match your production environment:

- `MOODLE_URL`: your production Moodle URL (Docker network name or hostname)
- `VEKTRA_API_URL`: your production Vektra API URL
- Generate new tokens and API keys for production
- Consider a longer polling interval for production (e.g., every 30 minutes)
