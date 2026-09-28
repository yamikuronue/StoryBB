#!/bin/bash
set -euo pipefail

BOARDDIR="${STORYBB_BOARDDIR:-/var/www/html}"
CONFIG_DIR="${STORYBB_CONFIG_DIR:-/var/storybb/config}"
DB_HOST="${STORYBB_DB_SERVER:-db}"
DB_PORT="${STORYBB_DB_PORT:-3306}"
DB_USER="${STORYBB_DB_USER:-storybb}"
DB_PASSWD="${STORYBB_DB_PASSWD:-storybb}"

mkdir -p "$CONFIG_DIR" \
	"$BOARDDIR/attachments" \
	"$BOARDDIR/cache" \
	"$BOARDDIR/custom_avatar" \
	"$BOARDDIR/cache/files"

# Named volumes start empty and hide image contents — restore security stubs.
if [[ ! -f "$BOARDDIR/attachments/index.php" ]]; then
	printf '%s\n' '<?php' 'if (substr($_SERVER["PHP_SELF"], -10) == "/index.php")' '	header("Location: ../index.php");' '?>' > "$BOARDDIR/attachments/index.php"
fi
if [[ ! -f "$BOARDDIR/attachments/.htaccess" ]]; then
	printf '%s\n' '<Files *>' '	Order Deny,Allow' '	Deny from all' '</Files>' > "$BOARDDIR/attachments/.htaccess"
fi
if [[ ! -f "$BOARDDIR/cache/index.php" ]]; then
	printf '%s\n' '<?php' 'if (substr($_SERVER["PHP_SELF"], -10) == "/index.php")' '	header("Location: ../index.php");' '?>' > "$BOARDDIR/cache/index.php"
fi
if [[ ! -f "$BOARDDIR/cache/.htaccess" ]]; then
	printf '%s\n' '<Files *>' '	Order Deny,Allow' '	Deny from all' '</Files>' > "$BOARDDIR/cache/.htaccess"
fi
if [[ ! -f "$BOARDDIR/custom_avatar/index.php" ]]; then
	printf '%s\n' '<?php' 'if (substr($_SERVER["PHP_SELF"], -10) == "/index.php")' '	header("Location: ../index.php");' '?>' > "$BOARDDIR/custom_avatar/index.php"
fi

# Restore persisted Settings.php into the document root when present.
if [[ -f "$CONFIG_DIR/Settings.php" ]]; then
	if [[ ! -f "$BOARDDIR/Settings.php" ]] || [[ "${STORYBB_FORCE_RECONFIG:-0}" == "1" ]]; then
		cp "$CONFIG_DIR/Settings.php" "$BOARDDIR/Settings.php"
	fi
fi

# Never leave a web installer in the document root.
rm -f "$BOARDDIR/install.php"

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

# Persist Settings.php across container recreates.
if [[ -f "$BOARDDIR/Settings.php" ]]; then
	cp "$BOARDDIR/Settings.php" "$CONFIG_DIR/Settings.php"
fi

chown -R www-data:www-data \
	"$BOARDDIR/attachments" \
	"$BOARDDIR/cache" \
	"$BOARDDIR/custom_avatar" \
	"$CONFIG_DIR" 2>/dev/null || true
if [[ -f "$BOARDDIR/Settings.php" ]]; then
	chown www-data:www-data "$BOARDDIR/Settings.php" 2>/dev/null || true
fi

exec "$@"
