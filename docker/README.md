# Moodle development environment

Local Moodle 5.1 instance for testing the `block_vektra` plugin.

## Prerequisites

- Docker (rootless or rootful)
- [vektra-stack](https://github.com/vektralabs/vektra-stack) running via Docker Compose

## Quick start

```bash
cp .env.example .env        # adjust ports/credentials if needed
docker compose up -d --build
docker compose logs -f moodle   # wait for "Moodle installed successfully"
```

Default URL: http://localhost:10180 (admin / Admin123!)

## Connect to Vektra

The Moodle container needs to reach the Vektra API on the Docker network:

```bash
# Connect Moodle to the vektra-stack network
docker network connect vektra-stack_default vektra-moodle

# Verify connectivity
docker exec vektra-moodle curl -sf http://vektra-stack-vektra-1:8000/health
```

This connection is lost when containers are recreated. Re-run after `docker compose down/up`.

## CORS

Add the Moodle origin to `VEKTRA_CORS_ORIGINS` in your vektra-stack `.env`:

```env
VEKTRA_CORS_ORIGINS=http://localhost,http://localhost:10180
```

Then recreate the Vektra container to reload the env.

## Plugin configuration

After Moodle is running and connected to Vektra, configure the plugin at
**Site administration > Plugins > Blocks > Vektra AI Assistant**:

| Setting | Value | Why |
|---------|-------|-----|
| API URL | `http://vektra-stack-vektra-1:8000` | Docker internal hostname (PHP server-side) |
| Public URL | `http://localhost:10800` | Host-accessible URL (browser widget JS) |
| API Key | *(admin-scoped key)* | Create via `/api/v1/api-keys` |

The two URLs differ because PHP runs inside the container (needs Docker hostname)
while the widget JS runs in the browser (needs host-mapped port).

## Plugin development

The plugin source is bind-mounted read-only from the repo root into the Moodle
container at `/var/www/html/public/blocks/vektra/`. PHP changes are reflected
immediately without rebuild.

After changing `version.php` or plugin metadata, run the upgrade CLI to register
the new version in Moodle's database (otherwise Moodle waits until the admin
visits the home page):

```bash
docker exec vektra-moodle php /var/www/html/admin/cli/upgrade.php --non-interactive
```

To clear Moodle caches after other plugin changes:

```bash
docker exec vektra-moodle php /var/www/html/admin/cli/purge_caches.php
```

## Ports

| Service | Default port | Override |
|---------|-------------|----------|
| Moodle | 10180 | `MOODLE_PORT` |
| MariaDB | 10306 | `MARIADB_PORT` |

## HTTPS deployment

To run Moodle behind an nginx HTTPS reverse proxy, three things must be configured
in addition to the standard setup above.

### 1. nginx reverse proxy

Use `docker/nginx/https-reverse-proxy.conf.example` as a starting point.
Copy it to `/etc/nginx/sites-available/` on the host, fill in `server_name`,
`ssl_certificate` paths, and the Vektra host-mapped port (default `10800`), then enable it.

The config handles three concerns in nginx, with no Apache `mod_proxy` needed:
- HTTP → HTTPS redirect (port 80)
- Moodle proxy on `/` → `127.0.0.1:MOODLE_PORT` (default `10180`)
- Vektra proxy on `/vektra/` → `127.0.0.1:10800`

If you change `MOODLE_PORT` in `.env`, update `proxy_pass` in the nginx config accordingly.

Critical: nginx must send `Host: moodle.internal` (or any hostname that does **not**
match the Moodle `wwwroot` host). Moodle 5 throws `reverseproxyabused` when
`reverseproxy=true` and the `Host` header equals the `wwwroot` host.

### 2. Moodle config.php

Inside the Moodle container at `/var/www/html/config.php`, set:

```php
$CFG->wwwroot      = "https://your-moodle.example.com";
$CFG->reverseproxy = true;
$CFG->sslproxy     = true;
```

`sslproxy` tells PHP to treat `X-Forwarded-Proto: https` as `$_SERVER['HTTPS'] = 'on'`.
Without it, Moodle generates `http://` asset URLs even over HTTPS.

### 3. Plugin Public URL

After switching to HTTPS, update the **Public URL** plugin setting to the HTTPS origin.
This is the URL the browser uses to load the chatbot widget JS and call the Vektra API.

Via CLI (inside the Moodle container):

```bash
docker exec vektra-moodle php /var/www/html/admin/cli/cfg.php \
  --component=block_vektra --name=publicurl --set="https://your-moodle.example.com/vektra"
```

Or via **Site administration > Plugins > Blocks > Vektra AI Assistant**.

Also add the HTTPS origin to `VEKTRA_CORS_ORIGINS` in the vektra-stack `.env` and
recreate the Vektra container (`docker compose up -d`).

### 4. n8n ingestion workflow

After changing `wwwroot` to HTTPS, Moodle generates all file download URLs with the
HTTPS base. Update `MOODLE_URL` in `n8n/.env` to match:

```env
MOODLE_URL=https://your-moodle.example.com
```

The n8n workflow supports HTTPS natively — no other changes required.
See `n8n/README.md` → Deployment to Production → HTTPS deployments.

## Remote access (SSH tunnel)

When accessing from a remote machine, tunnel **both** Moodle and Vektra ports:

```bash
ssh -L 10180:localhost:10180 -L 10800:localhost:10800 user@host -N
```

Both are required because the browser loads Moodle pages from the Moodle port and
the chatbot widget JS + API calls directly from the Vektra port. If only Moodle is
tunneled, the widget silently fails to load (no floating chat button).

## Reset

```bash
docker compose down       # keep data
docker compose down -v    # full reset (database + moodledata)
```

## Assessment bot dev environments (Moodle 4.3 and 5.2)

The sections above describe the original single Moodle 5.1 setup (`docker-compose.yml`). For the
assessment bot (plan `assess-moodle-ws`) there are two more, independent environments built from
one version-generic file, `compose.moodle.yml`, plus an env file per version. Containers, volumes
and loopback ports never collide with the 5.1 setup or with each other.

| | Moodle 4.3.11+ | Moodle 5.2.4+ |
|---|---|---|
| env file | `env.43` | `env.52` |
| container | `vektra-moodle43` | `vektra-moodle52` |
| PHP / web docroot | 8.2 / `/var/www/html` | 8.3 / `/var/www/html/public` |
| URL (loopback) | http://localhost:8080 | http://localhost:8082 |
| MariaDB (loopback) | 10406 | 10506 |

Credentials are local dev defaults (`admin` / `Admin123!`), never use them elsewhere. Run everything
below from this `docker/` directory (replace `NN` with `43` or `52`).

### Bring-up (one command per version)

```bash
make moodle43-up      # or: make moodle52-up   (build and start; Moodle installs in the background)
```

The first start downloads the pinned Moodle tarball (SHA-256 checked in the env file) and runs the
CLI installer; follow it with `docker logs -f vektra-moodleNN`. `make moodleNN-down` stops it and keeps
the data.

### Provisioning the service account

```bash
make moodleNN-provision
```

Runs `provision/assess_service_account.php` (idempotent): the read-only `vektra_assess_svc` account,
its role, the `vektra_assess` external service with the exact function list, the permanent token and
the negative accounts used by the REQ-079 tests. The report, tokens included, goes to
`fixtures/out/provision-m43.json` / `provision-m52.json`.

### Dev dependencies and fixture seeding

The Moodle data generators need the composer dev dependencies (PHPUnit and friends), which the image
does not ship. Install them once per container (needs network; idempotent):

```bash
make moodleNN-devdeps     # = docker exec -u root vektra-moodleNN bash /opt/vektra/fixtures/prepare_dev_deps.sh
make moodleNN-seed        # = docker exec -u www-data vektra-moodleNN php /opt/vektra/fixtures/seed_assess_fixtures.php
make moodleNN-check       # self-check only, seeds nothing
```

`fixtures/fixtures.json` is the declarative catalogue (one course `EAASSESS`: quizzes with every
question type and attempt state, grading methods, overrides, review-option variants, assignments with
cut-off / team / marking guide / rubric / marking workflow / hidden grades, submissions with PDF, scanned
PDF, DOCX, ODT, PPTX and an 11-file submission, groups, graders, a teacher without grade capability, one
`user_loggedinas` event). Every hidden field carries a unique `CANARY-<KIND>-<tag>` token.

The seeder writes `fixtures/out/manifest-m43.json` / `manifest-m52.json` (ids, EXPECTED values taken
from the catalogue, canaries, and the list of self-checks) and then verifies the seed directly in the
Moodle DB: row counts and values per fixture (attempts by state, overrides, extensions, files,
grading instances, grade items, canaries, capabilities, module visibility). **It exits non-zero on any
mismatch**, so a fixture that is silently missing can never look seeded. A fixture a version cannot
seed is recorded as `"seeded": false` with a reason and printed loudly. Re-running is safe: when the
course already exists nothing is seeded and only the self-check runs (exit 0 when it passes).

`fixtures/out/` is git-ignored (it holds tokens). Seeding defines `BEHAT_TEST` after bootstrap to
unlock the quiz generator; use it on these dev containers only.

### Connect to the vektra stack

```bash
docker network connect vektra-stack_default vektra-moodleNN
docker exec vektra-moodleNN curl -sf http://vektra-stack-vektra-1:8000/health
```

The connection is lost when the container is recreated; re-run it after `make moodleNN-up` recreated
the container.

### Reset

```bash
make moodleNN-down    # keep data
make moodleNN-reset   # removes the database and moodledata volumes of that version only
```

After a reset: `make moodleNN-up`, `make moodleNN-provision`, `make moodleNN-devdeps` (the composer
`vendor/` lives in the container, not in a volume, so a recreated container needs it again), then
`make moodleNN-seed`.

### Known issue: release zip

`.github/workflows/release.yml` currently zips the whole repository, including this `docker/`
directory. Keep real secrets out of `docker/` (the generated reports live in the git-ignored
`fixtures/out/`); excluding `docker/` from the release is owned by plugin plan P1.
