#!/bin/sh
#  Usage:
#    ./refresh_github.sh install [limit]   # register cron schedule
#    ./refresh_github.sh run [limit]       # refresh once (used by cron)
#  Defaults: limit = 20, schedule = every 6 hours.

set -u

# Resolve the project root relative to this script (two levels up).
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

CMD="${1:-install}"
LIMIT="${2:-20}"
SCHEDULE="${SCHEDULE:-0 */6 * * *}"
PHP_BIN="${PHP_BIN:-php}"
LOG_FILE="${LOG_FILE:-/tmp/refresh_github.log}"

case "$LIMIT" in *[!0-9]*|'') LIMIT=20 ;; esac

do_run() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] refreshing up to ${LIMIT} stale profiles" >> "${LOG_FILE}"
    (cd "${ROOT_DIR}" && "${PHP_BIN}" api/cron/refresh_github.php "${LIMIT}" >> "${LOG_FILE}" 2>&1)
}

if [ "$CMD" = "run" ]; then
    do_run
    exit 0
fi

# Default (or "install")
CRON_LINE="${SCHEDULE} ${SCRIPT_DIR}/refresh_github.sh run ${LIMIT}"
( crontab -l 2>/dev/null | grep -vF 'refresh_github.sh' ; echo "${CRON_LINE}" ) | crontab -

echo "Installed cron job:"
crontab -l | grep -F 'refresh_github.sh'
