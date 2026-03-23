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
