#!/bin/bash
set -e

# Paths
PERSIST_DIR=/var/www/web/config-tool-data
DB_PATH=${PERSIST_DIR}/db/.ht.sqlite
DRUSH=/var/www/vendor/bin/drush

# Ensure directories exist with correct permissions
mkdir -p ${PERSIST_DIR}/db ${PERSIST_DIR}/files
chown -R www-data:www-data ${PERSIST_DIR}

# Auto-install Drupal if DB is missing
if [ ! -f "${DB_PATH}" ]; then
  echo "No DB found — installing PPUC Config Tool with SQLite..."

  if [ ! -f "${DRUSH}" ]; then
    echo "ERROR: Drush not found at ${DRUSH}"
    exit 1
  fi

  # Run drush as www-data
  sudo -u www-data ${DRUSH} site:install ppuc \
    --site-name="Pinball Power-Up Controller" \
    --account-name=admin \
    --account-pass=admin \
    --existing-config \
    --yes

  sudo -u www-data ${DRUSH} dcdi \
    --folder=sites/default/files/default_content \
    --preserve-ids \
    --yes

  echo "PPUC Config Tool installation completed."
else
  echo "Existing DB found - check for updates"

  # Run drush commands as www-data
  sudo -u www-data ${DRUSH} deploy

  sudo -u www-data ${DRUSH} dcdi \
    --folder=sites/default/files/default_content \
    --preserve-ids \
    --yes
fi

# Ensure permissions are correct
chown -R www-data:www-data ${PERSIST_DIR}
chmod 755 ${PERSIST_DIR}
[ -f "${DB_PATH}" ] && chmod 664 "${DB_PATH}"

exec apache2-foreground
