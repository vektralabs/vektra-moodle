#!/bin/bash
set -e

# Defaults from environment variables
MOODLE_URL=${MOODLE_URL:-http://localhost}
DB_HOST=${DB_HOST:-mariadb}
DB_NAME=${DB_NAME:-moodle}
DB_USER=${DB_USER:-moodleuser}
DB_PASS=${DB_PASS:-moodlepass}
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASS=${ADMIN_PASS:-Admin123!}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}

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

echo "Starting Apache..."
exec "$@"
