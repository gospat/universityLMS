#!/usr/bin/env bash
# Moodle ULMS cron safe wrapper — run from crontab every minute.
#
# Features:
#   * flock() single-flight lock — prevents cron pile-up if a run goes long.
#   * Sets CLI_SCRIPT=1 so Moodle config.php accepts web-root require from CLI.
#   * All output (stdout + stderr) appended to daily rotated log with mtime YYYY-MM-DD.
#   * chdir() into the ULMS repo root so relative $CFG->dataroot paths resolve.
#
# Install via: php local/ulms_dashboard/cli/install_cron.php --install
# Manual usage:  bash local/ulms_dashboard/cli/run_moodle_cron.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
LOG_DIR="${REPO_ROOT}/var/log/cron"
LOCK_DIR="${REPO_ROOT}/var/run"
LOG_FILE="${LOG_DIR}/moodle-cron-$(date +%Y-%m-%d).log"
LOCK_FILE="${LOCK_DIR}/moodle-cron.lock"
PHP_BIN="${PHP_BIN:-$(command -v php)}"

mkdir -p "${LOG_DIR}" "${LOCK_DIR}"

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] SKIP — previous moodle-cron run still holds ${LOCK_FILE}" >> "${LOG_FILE}" 2>&1
    exit 0
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] START run_moodle_cron.sh pid=$$" >> "${LOG_FILE}" 2>&1

cd "${REPO_ROOT}"
set +e
CLI_SCRIPT=1 "${PHP_BIN}" -d memory_limit=512M admin/cli/cron.php >> "${LOG_FILE}" 2>&1
RC=$?
set -e

echo "[$(date +'%Y-%m-%d %H:%M:%S')] END   run_moodle_cron.sh rc=${RC}" >> "${LOG_FILE}" 2>&1

# Rotate: keep last 14 days of logs only.
find "${LOG_DIR}" -maxdepth 1 -type f -name 'moodle-cron-*.log' -mtime +14 -delete >/dev/null 2>&1 || true

exit ${RC}
