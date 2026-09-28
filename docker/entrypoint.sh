#!/bin/bash
set -euo pipefail

# The image is designed to run with a read-only root filesystem. Writable
# paths are the config volume, attachments, custom_avatar, cache (tmpfs) and
# cache/files (persistent uploads such as smileys and favicons).

BOARDDIR="${STORYBB_BOARDDIR:-/var/www/html}"
CONFIG_DIR="${STORYBB_CONFIG_DIR:-/var/storybb/config}"
DB_HOST="${STORYBB_DB_SERVER:-db}"
DB_PORT="${STORYBB_DB_PORT:-3306}"
DB_USER="${STORYBB_DB_USER:-storybb}"
DB_PASSWD="${STORYBB_DB_PASSWD:-storybb}"

WRITABLE_DIRS=(
	"$BOARDDIR/attachments"
	"$BOARDDIR/custom_avatar"
	"$BOARDDIR/cache"
	"$BOARDDIR/cache/files"
)

for dir in "${WRITABLE_DIRS[@]}"; do
	mkdir -p "$dir"
done

if ! touch "$CONFIG_DIR/.write-test" 2>/dev/null; then
	echo "ERROR: $CONFIG_DIR is not writable; mount a volume there for Settings.php." >&2
	exit 1
fi
rm -f "$CONFIG_DIR/.write-test"

echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
export MYSQL_PWD="${DB_PASSWD}"
for i in $(seq 1 60); do
	if mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" --silent 2>/dev/null; then
		echo "Database is reachable."
		break
	fi
	if [[ "$i" -eq 60 ]]; then
		echo "ERROR: timed out waiting for database ${DB_HOST}:${DB_PORT}" >&2
		echo "Check STORYBB_DB_SERVER/PORT and that this host is allowed to connect (DigitalOcean Trusted Sources / firewall)." >&2
		exit 1
	fi
	sleep 2
done
unset MYSQL_PWD

echo "Running StoryBB headless installer (idempotent)..."
php "$BOARDDIR/docker/install.php"

# The web server may read its configuration but never rewrite it.
chown root:www-data "$CONFIG_DIR"
chmod 0750 "$CONFIG_DIR"
if [[ -f "$CONFIG_DIR/Settings.php" ]]; then
	chown root:www-data "$CONFIG_DIR/Settings.php"
	chmod 0640 "$CONFIG_DIR/Settings.php"
fi

# Files the installer created as root must be manageable by the web server.
for dir in "${WRITABLE_DIRS[@]}"; do
	find "$dir" \! -user www-data -exec chown www-data:www-data {} +
done

exec "$@"
