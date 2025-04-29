#!/bin/bash
set -e

# Paths
PERSIST_DIR=/var/www/web/config-tool-data
DB_PATH=${PERSIST_DIR}/db/.ht.sqlite
DRUSH=/var/www/vendor/bin/drush

mkdir -p ${PERSIST_DIR}/db ${PERSIST_DIR}/files
chown -R www-data:www-data ${PERSIST_DIR}

# Auto-install Drupal if DB is missing
if [ ! -f "${DB_PATH}" ]; then
  echo "No DB found — installing PPUC Config Tool with SQLite..."

  if [ ! -f "${DRUSH}" ]; then
    echo "ERROR: Drush not found at ${DRUSH}"
    exit 1
  fi

  ${DRUSH} site:install ppuc \
    --site-name="Pinball Power-Up Controller" \
    --account-name=admin \
    --account-pass=admin \
    --existing-config \
    --yes

  ${DRUSH} dcdi \
    --folder=sites/default/files/default_content \
    --preserve-ids \
    --yes

  echo \"PPUC Config Tool installation completed.\"
else
  echo \"Existing DB found - check for updates\"

  ${DRUSH} deploy

  ${DRUSH} dcdi \
    --folder=sites/default/files/default_content \
    --preserve-ids \
    --yes
fi

exec apache2-foreground
