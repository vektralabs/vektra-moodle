#!/usr/bin/env bash
# Installs the Moodle composer dev dependencies (PHPUnit & co.) that the data generators used by
# seed_assess_fixtures.php need. Idempotent; needs network on first run (dev environment only).
# Run inside the container as root:
#   docker exec -u root vektra-moodle43 bash /opt/vektra/fixtures/prepare_dev_deps.sh
# (or `make moodle43-devdeps`). Also makes fixtures/out writable by www-data so the seeder can
# write its manifest there.
set -euo pipefail
ROOT=/var/www/html   # composer.json lives here in 4.x and in 5.x (5.x serves from ROOT/public)
OUT=/opt/vektra/fixtures/out
mkdir -p "$OUT"; chmod 0777 "$OUT"

if [ -f "$ROOT/vendor/autoload.php" ] && [ -f "$ROOT/vendor/bin/phpunit" ]; then
  echo "dev deps already installed in $ROOT/vendor"; exit 0
fi
if [ ! -x /usr/local/bin/composer ]; then
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  [ "$(php -r 'echo hash_file("sha384","/tmp/composer-setup.php");')" = "$EXPECTED" ] \
    || { echo "composer installer checksum mismatch" >&2; exit 1; }
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
fi
mkdir -p /tmp/composer-home; chown www-data:www-data /tmp/composer-home
su -s /bin/bash www-data -c "cd $ROOT && HOME=/tmp/composer-home COMPOSER_HOME=/tmp/composer-home composer install --dev --no-interaction --no-progress --prefer-dist"
test -f "$ROOT/vendor/autoload.php"
echo "dev deps installed"
