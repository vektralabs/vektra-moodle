#!/bin/bash
set -e

# Defaults from environment variables.
# Export so PHP subprocesses can read them via getenv().
export MOODLE_URL=${MOODLE_URL:-http://localhost}
export DB_HOST=${DB_HOST:-mariadb}
export DB_NAME=${DB_NAME:-moodle}
export DB_USER=${DB_USER:-moodleuser}
export DB_PASS=${DB_PASS:-moodlepass}
export ADMIN_USER=${ADMIN_USER:-admin}
export ADMIN_PASS=${ADMIN_PASS:-Admin123!}
export ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}

echo "========================================="
echo "Moodle Docker container starting"
echo "========================================="
echo "Moodle URL: $MOODLE_URL"
echo "Database host: $DB_HOST"
echo "Database name: $DB_NAME"
echo "========================================="

# Wait for MariaDB
echo "Waiting for MariaDB..."
RETRIES=30
until php -r "new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));" &> /dev/null || [ $RETRIES -eq 0 ]; do
    echo "Waiting for MariaDB, $((RETRIES--)) remaining attempts..."
    sleep 2
done

if [ $RETRIES -eq 0 ]; then
    echo "ERROR: Could not connect to MariaDB"
    exit 1
fi

echo "MariaDB is ready."

# Install Moodle if not already done
if [ ! -f /var/www/html/config.php ]; then
    echo "========================================="
    echo "Installing Moodle..."
    echo "========================================="
    cd /var/www/html

    # Check if database tables already exist (previous install without config.php)
    DB_EXISTS=$(php -r "
        \$m = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));
        \$r = \$m->query('SHOW TABLES');
        echo \$r->num_rows;
    " 2>/dev/null || echo "0")

    SKIP_DB=""
    if [ "$DB_EXISTS" -gt 0 ]; then
        echo "Database tables found, skipping database setup..."
        SKIP_DB="--skip-database"
    fi

    php admin/cli/install.php \
        --wwwroot="${MOODLE_URL}" \
        --dataroot="/var/moodledata" \
        --dbtype="mariadb" \
        --dbhost="${DB_HOST}" \
        --dbname="${DB_NAME}" \
        --dbuser="${DB_USER}" \
        --dbpass="${DB_PASS}" \
        --fullname="Moodle Site" \
        --shortname="Moodle" \
        --adminuser="${ADMIN_USER}" \
        --adminpass="${ADMIN_PASS}" \
        --adminemail="${ADMIN_EMAIL}" \
        --non-interactive \
        --agree-license \
        $SKIP_DB

    # Only fix config.php ownership - Dockerfile already set correct permissions
    # on the rest of /var/www/html. No need to chown the entire tree at runtime.
    chown www-data:www-data /var/www/html/config.php
    chmod 644 /var/www/html/config.php

    echo "========================================="
    echo "Moodle installed successfully."
    echo "========================================="
    echo "URL: $MOODLE_URL"
    echo "Admin: $ADMIN_USER"
    echo "========================================="
else
    echo "Moodle already installed, skipping."
fi

# Inject reverse proxy settings when MOODLE_URL is HTTPS.
# nginx terminates SSL and forwards plain HTTP to Apache; without these
# settings Moodle sees HTTP, compares against https:// wwwroot, and loops.
# Each setting is checked independently so a partial config (only one
# of the two present) is repaired rather than skipped.
if [[ "$MOODLE_URL" == https://* ]] && [ -f "/var/www/html/config.php" ]; then
    INJECTED=0
    # The address pattern requires an actual assignment to $CFG->wwwroot
    # (anchored on `=`, allowing leading whitespace). Without the `=`
    # anchor the previous version also matched lines like
    # `$CFG->wwwroot_backup = ...` and would inject after each match.
    if ! grep -Eq '^\s*\$CFG->reverseproxy\s*=' "/var/www/html/config.php"; then
        sed -i "/^\s*\$CFG->wwwroot\s*=/a \$CFG->reverseproxy = true;" "/var/www/html/config.php"
        INJECTED=1
    fi
    if ! grep -Eq '^\s*\$CFG->sslproxy\s*=' "/var/www/html/config.php"; then
        sed -i "/^\s*\$CFG->wwwroot\s*=/a \$CFG->sslproxy     = true;" "/var/www/html/config.php"
        INJECTED=1
    fi
    if [ "$INJECTED" -eq 1 ]; then
        echo "Reverse proxy settings ensured in config.php."
    fi
fi

# Fix Moodle HTTP security for Docker integration.
# By default Moodle blocks curl to 172.16.0.0/12 (Docker network range)
# and only allows ports 443 and 80. We unblock Docker IPs and add port 8000
# so the plugin can reach the Vektra API on the internal Docker network.
if [ -f /var/www/html/config.php ]; then
    php -r "
        define('CLI_SCRIPT', true);
        require('/var/www/html/config.php');
        set_config('curlsecurityallowedport', \"443\n80\n8000\");
        \$blocked = \"127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n0.0.0.0\nlocalhost\n169.254.169.254\n0000::1\";
        set_config('curlsecurityblockedhosts', \$blocked);
    " 2>/dev/null && echo "HTTP security configured for Docker integration." || true
fi

# Ensure n8n WS token exists in Moodle DB.
# The token must match MOODLE_WS_TOKEN in the n8n stack .env.
# tokentype=0 (EXTERNAL_TOKEN_PERMANENT) is required — omitting it causes insert failure.
if [ -n "${N8N_WS_TOKEN:-}" ] && [ -f /var/www/html/config.php ]; then
    php -r "
        define('CLI_SCRIPT', true);
        require('/var/www/html/config.php');
        \$token_value = getenv('N8N_WS_TOKEN');
        \$service = \$DB->get_record('external_services', ['shortname' => 'n8n_ingestion']);
        if (!\$service) { echo \"n8n_ingestion WS service not found, skipping token setup.\n\"; exit(0); }
        \$admin = \$DB->get_record('user', ['username' => getenv('ADMIN_USER') ?: 'admin']);
        if (!\$admin) { echo \"Admin user not found, skipping token setup.\n\"; exit(0); }
        \$existing = \$DB->get_record('external_tokens', ['token' => \$token_value, 'externalserviceid' => \$service->id]);
        if (\$existing) { echo \"n8n WS token already present.\n\"; exit(0); }
        \$r = new stdClass();
        \$r->token              = \$token_value;
        \$r->tokentype          = 0;
        \$r->userid             = \$admin->id;
        \$r->externalserviceid  = \$service->id;
        \$r->contextid          = context_system::instance()->id;
        \$r->creatorid          = \$admin->id;
        \$r->timecreated        = time();
        \$r->validuntil         = 0;
        \$DB->insert_record('external_tokens', \$r);
        echo \"n8n WS token created.\n\";
    " 2>/dev/null && true || echo "WARNING: n8n WS token setup failed (non-fatal)."
fi

echo "Starting Apache..."
exec "$@"
