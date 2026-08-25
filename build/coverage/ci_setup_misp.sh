#!/usr/bin/env bash
# Install and configure a MISP instance for the coverage workflow.
#
# Mirrors the install performed inline by .github/workflows/main.yml. Kept as
# a script so the two workflows cannot drift in how MISP is installed.
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.3}"
WORKSPACE="${GITHUB_WORKSPACE:-$(pwd)}"
HOST="${HOST:-127.0.0.1}"

cd "$WORKSPACE"

# --- permissions -----------------------------------------------------------
sudo chown -R "$USER":www-data "$WORKSPACE"
sudo chmod -R 775 "$WORKSPACE/app/tmp" "$WORKSPACE/app/files"

# www-data must be able to TRAVERSE down to the workspace. On a GitHub runner
# /home/runner is not world-executable, so `sudo -u www-data app/Console/cake`
# fails with "Could not open input file: .../app/Console/cake.php" - the file
# is there, but www-data cannot walk the path to it. main.yml does the same.
namei -m "$WORKSPACE" || true
sudo chmod +x / /home /home/runner /home/runner/work 2>/dev/null || true

# --- config ----------------------------------------------------------------
cp --update=none app/Config/bootstrap.default.php app/Config/bootstrap.php
cp --update=none app/Config/core.default.php      app/Config/core.php
cp --update=none app/Config/config.default.php    app/Config/config.php
cp --update=none build/database.php               app/Config/database.php
cp --update=none build/email.php                  app/Config/email.php

# Must come AFTER the copies above: chmodding the directory first leaves the
# newly created config.php owned by the runner and mode 644, so
# `cake Admin setSetting` - which rewrites the file in place - is rejected
# with "MISP tried to save a malformed config file or you dont have
# permission to write to config file". main.yml applies these perms after
# copying for the same reason.
sudo chown -R "$USER":www-data "$WORKSPACE/app/Config"
sudo chmod -R 777 "$WORKSPACE/app/Config"

# --- database --------------------------------------------------------------
mysql -h 127.0.0.1 --port 3306 -u root -pbar \
      -e "SET GLOBAL sql_mode = 'STRICT_ALL_TABLES';"
mysql -h 127.0.0.1 --port 3306 -u misp -pblah misp < INSTALL/MYSQL.sql

# --- apache ----------------------------------------------------------------
sudo cp -f build/github-action-ci-apache /etc/apache2/sites-available/misp.conf
sudo sed -e "s?%GITHUB_WORKSPACE%?${WORKSPACE}?g" --in-place /etc/apache2/sites-available/misp.conf
sudo sed -e "s?%HOST%?${HOST}?g"                  --in-place /etc/apache2/sites-available/misp.conf
sudo a2dissite 000-default
sudo a2ensite misp.conf
sudo a2enmod rewrite

# Instrument mod_php. auto_prepend_file must be set for BOTH SAPIs, since the
# live suite exercises MISP over HTTP and through the cake console.
for sapi in apache2 cli; do
    ini="/etc/php/${PHP_VERSION}/${sapi}/php.ini"
    [ -f "$ini" ] || continue
    sudo sed -i -E 's/^\s*memory_limit\s*=.*/memory_limit = 2048M/' "$ini"
    {
        echo "pcov.enabled=1"
        echo "pcov.directory=${WORKSPACE}/app"
        echo "auto_prepend_file=${WORKSPACE}/build/coverage/covcollect.php"
    } | sudo tee -a "$ini" > /dev/null
done
sudo systemctl restart apache2 || sudo systemctl start apache2

# --- GPG -------------------------------------------------------------------
mkdir -p "$WORKSPACE/.gnupg" && chmod 700 "$WORKSPACE/.gnupg"
gpg --no-tty --batch --pinentry-mode=loopback --passphrase travistest \
    --homedir "$WORKSPACE/.gnupg" --gen-key build/gpg
sudo chown -R www-data:www-data "$WORKSPACE/.gnupg"

# --- MISP settings ---------------------------------------------------------
cake() { sudo -u www-data app/Console/cake "$@"; }

cake Admin setSetting "MISP.osuser" "www-data"
cake Admin runUpdates

for kv in "Session.autoRegenerate 0" "Session.timeout 600" "Session.cookieTimeout 3600" \
          "MISP.email info@admin.test" "MISP.baseurl http://${HOST}" \
          "MISP.redis_host 127.0.0.1" "MISP.redis_port 6379" "MISP.redis_database 13" \
          "GnuPG.email info@admin.test" "GnuPG.homedir ${WORKSPACE}/.gnupg" \
          "GnuPG.password travistest" "MISP.download_gpg_from_homedir 1" \
          "SimpleBackgroundJobs.enabled 0" "MISP.server_settings_skip_backup_rotate 1" \
          "MISP.python_bin ${WORKSPACE}/venv/bin/python"; do
    # shellcheck disable=SC2086
    cake Admin setSetting $kv
done
cake Admin setSetting --force debug true
cake Admin setSetting --force "Security.allow_self_registration" true

cake Admin updateJSON
cake Admin live 1

# The auth key must exist before host_org_id can reference an organisation.
cake User init | tail -1 | sudo tee "$WORKSPACE/key.txt" > /dev/null
cake Admin setSetting "MISP.host_org_id" 1

echo "AUTH=$(cat "$WORKSPACE/key.txt")" >> "${GITHUB_ENV:-/dev/null}"
echo "MISP installed and live at http://${HOST}"
