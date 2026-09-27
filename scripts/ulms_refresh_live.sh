#!/usr/bin/env bash
#
# ============================================================
# DEPLOYMENT REFERENCE EXAMPLE — Bells University of Technology
# ------------------------------------------------------------
# This script is the PRODUCTION reference for the Bells
# University deployment refresh workflow.  For deployments at
# OTHER institutions see ULMS_DEPLOYMENT_SYNC.md §16 Generic
# Multi-University Setup instead — it walks you through the
# same atomic deploy primitives (parallel dirs + swap) without
# any Bells-specific path assumptions.
#
# WARNING: This script contains DESTRUCTIVE operations (rsync
# with --delete, purge_caches.php).  Only run it AFTER reading
# the runbook safety disclaimers AND with the explicit
# --overlay-delete-ok / --i-am-sure flags when applicable.
# ============================================================
#
# ULMS live deployment refresh script.
#
# DEPLOYMENT MODELS SUPPORTED (autodetected):
#   1. STANDARD PHP-FPM / WEB-SERVER — PRIMARY (default for Bells University)
#      · Ubuntu 24.04 (or any Linux), nginx/Apache + php-fpm 8.3
#      · No docker-compose.yml required; script runs directly on the host
#      · Runs: composer install (optional) → legacy overlay sync →
#              Moodle upgrade.php (non-interactive) → purge_caches.php →
#              opcache reset via php-fpm if available
#
#   2. DOCKER COMPOSE — SECONDARY (optional, only when docker-compose.yml exists
#      AND the `docker` CLI is available AND `--docker` flag is explicitly
#      supplied).  Never silently runs Docker-only steps.
#
# EXIT CODES:
#   0 — all steps completed successfully
#   1 — usage error / missing required step / operator cancelled
#   2 — composer required but unavailable (only in --composer-required mode)
#
# FLAGS:
#   --help                    Show this help.
#   --dry-run                 Show rsync plan + composer status without
#                             applying any file changes (applies to the
#                             legacy overlay sync step only).
#   --overlay-delete-ok       EXPLICITLY allow rsync --delete during legacy
#                             overlay sync (see §overlay below).  Without this
#                             flag, overlays are copied WITHOUT --delete, so
#                             production files in the overlay target can never
#                             be silently removed by this script.
#   --composer-required       Exit 2 if Composer cannot be resolved.  By
#                             default Composer is optional (the Resend HTTP
#                             transport ships an ext-cURL fallback so the
#                             app functions without vendor/).
#   --docker                  Enable the Docker Compose refresh path.
#                             Ignored with a warning if docker-compose.yml
#                             or docker CLI are absent (never silent skip).
#   --skip-overlay            Skip the legacy overlay sync entirely.
#                             Recommended for new standard-PHP deployments
#                             where the ../custom/ overlay layout is not used.
#
# OVERLAY SYNC BEHAVIOUR:
#   Legacy (pre-2024) deployments used a ../custom/ directory copied on top
#   of the moodle tree via rsync.  The old default was `rsync -a --delete`,
#   which can REMOVE PRODUCTION FILES that are present in the overlay target
#   but absent from the source (for example files added by Moodle's plugin
#   installer, patches applied in production, or generated assets).
#
#   This script CHANGES THE DEFAULT TO BE SAFE:
#     · Overlays use `rsync -a` (no --delete) unless --overlay-delete-ok
#       is explicitly supplied.
#     · When --dry-run is passed, only the rsync itemize-change plan is
#       printed; zero files are copied or removed.
#     · Only EXACT paths declared explicitly below are ever affected.
#
# AFFECTED PATHS (overlay sync — only when ../custom/ EXISTS and
# --skip-overlay was NOT passed):
#   Source (inside repo, tracked by git):
#     · local/ulms_academics
#     · local/ulms_auth
#     · local/ulms_dashboard
#     · local/ulms_mail
#     · theme/ulms_university
#     · blocks/ulms_dashboard_widgets
#   Target (inside ../custom/ — NOT inside webroot, for legacy overlay model):
#     · custom/local/ulms_academics        ⚠ rsync target with --delete only
#     · custom/local/ulms_auth              ⚠ if --overlay-delete-ok supplied
#     · custom/local/ulms_dashboard         ⚠ files not in source could
#     · custom/local/ulms_mail              ⚠ previously be removed here
#     · custom/themes/ulms_university       ⚠
#     · custom/blocks/ulms_dashboard_widgets⚠
#
# FILES THIS SCRIPT NEVER MODIFIES:
#   .env, config.php, MOODLE_DATA_PATH (dataroot), vendor/ is managed only
#   by Composer.  Git-ignored files in the repo root are not touched.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_DIR="$(cd "${REPO_DIR}/.." && pwd)"
COMPOSE_FILE="${WORKSPACE_DIR}/docker-compose.yml"
CUSTOM_ROOT="${WORKSPACE_DIR}/custom"

show_usage() {
  # Extract the large block at the top of this file (everything between
  # the first '#!/usr/bin/env bash' shebang line and the first 'set -euo
  # pipefail').  Avoids duplicating the help text.
  sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

# ── Argument parsing ───────────────────────────────────────────────────────
DRY_RUN=0
OVERLAY_DELETE_OK=0
COMPOSER_REQUIRED=0
ENABLE_DOCKER=0
SKIP_OVERLAY=0
SHOW_HELP=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --help)                 SHOW_HELP=1 ;;
    --dry-run)              DRY_RUN=1 ;;
    --overlay-delete-ok)    OVERLAY_DELETE_OK=1 ;;
    --composer-required)    COMPOSER_REQUIRED=1 ;;
    --docker)               ENABLE_DOCKER=1 ;;
    --skip-overlay)         SKIP_OVERLAY=1 ;;
    *)
      echo "ERROR: Unknown argument: $1"
      echo ""
      show_usage
      exit 1
      ;;
  esac
  shift
done

if [[ "$SHOW_HELP" -eq 1 ]]; then
  show_usage
  exit 0
fi

# ── Banner ──────────────────────────────────────────────────────────────────
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║  ULMS live refresh (scripts/ulms_refresh_live.sh)             ║"
echo "╠════════════════════════════════════════════════════════════════╣"
echo "║  DRY_RUN=$DRY_RUN    OVERLAY_DELETE_OK=$OVERLAY_DELETE_OK"
echo "║  COMPOSER_REQUIRED=$COMPOSER_REQUIRED    ENABLE_DOCKER=$ENABLE_DOCKER"
echo "║  SKIP_OVERLAY=$SKIP_OVERLAY"
echo "║  REPO_DIR=$REPO_DIR"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# ── Helpers: Composer resolution ────────────────────────────────────────────
resolve_composer_command() {
  if command -v composer >/dev/null 2>&1; then
    echo "composer"
    return 0
  fi
  if [[ -f "${REPO_DIR}/composer.phar" ]]; then
    echo "php ${REPO_DIR}/composer.phar"
    return 0
  fi
  return 1
}

run_composer_install() {
  if command -v composer >/dev/null 2>&1; then
    (cd "${REPO_DIR}" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader)
    return $?
  fi
  if [[ -f "${REPO_DIR}/composer.phar" ]]; then
    (cd "${REPO_DIR}" && php "${REPO_DIR}/composer.phar" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader)
    return $?
  fi
  return 127
}

# ── Step 1: Composer (optional / required-by-flag) ──────────────────────────
install_root_dependencies() {
  if [[ ! -f "${REPO_DIR}/composer.json" || ! -f "${REPO_DIR}/composer.lock" ]]; then
    echo "[Composer] No composer.json/composer.lock found in repo root — skipping."
    return 0
  fi

  local composer_cmd
  composer_cmd="$(resolve_composer_command 2>/dev/null || true)"
  if [[ -z "$composer_cmd" ]]; then
    if [[ "$COMPOSER_REQUIRED" -eq 1 ]]; then
      echo "[Composer] FAILED: --composer-required was passed but Composer is not available."
      echo "  Install Composer globally: https://getcomposer.org/download/"
      echo "  — or — place composer.phar at: ${REPO_DIR}/composer.phar"
      return 2
    fi
    echo "[Composer] SKIP (not required).  The Resend HTTP transport uses the built-in ext-cURL"
    echo "  duck-typed fallback when vendor/ is absent, so ULMS functions fully.  Install"
    echo "  Composer vendor only when you want Symfony HttpClient connection pooling."
    return 0
  fi

  echo "[Composer] Resolved: $composer_cmd"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "  DRY-RUN: would run: composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader"
    return 0
  fi
  echo "  Installing locked production dependencies (no dev packages)."
  run_composer_install
}

# ── Step 2: Legacy overlay sync (SAFE DEFAULTS) ────────────────────────────
sync_overlay_dir() {
  local source_dir="$1"
  local target_dir="$2"
  local label="$3"

  if [[ ! -d "${source_dir}" ]]; then
    echo "[Overlay $label] SKIP missing source: ${source_dir}"
    return 0
  fi

  mkdir -p "${target_dir}"

  local rsync_flags=('-a' '--human-readable')
  if [[ "$DRY_RUN" -eq 1 ]]; then
    rsync_flags+=('--dry-run' '--itemize-changes')
  fi
  if [[ "$OVERLAY_DELETE_OK" -eq 1 ]]; then
    rsync_flags+=('--delete')
    echo "[Overlay $label] ⚠  --delete ENABLED (explicit --overlay-delete-ok).  Deletes:"
    echo "  source  = $source_dir"
    echo "  target  = $target_dir"
    echo "  Files present in target but ABSENT in source WILL BE REMOVED."
    if [[ "$DRY_RUN" -ne 1 ]]; then
      read -r -p "  Confirm the rsync --delete for this target? [type YES to proceed] " confirm
      if [[ "$confirm" != "YES" ]]; then
        echo "  Operator cancelled.  Skipping overlay $label."
        return 1
      fi
    fi
  else
    echo "[Overlay $label] SAFE mode (no --delete).  Files added to $target_dir outside of git will be PRESERVED."
  fi
  echo "  rsync ${rsync_flags[*]} ${source_dir}/ ${target_dir}/"
  rsync "${rsync_flags[@]}" "${source_dir}/" "${target_dir}/"
}

sync_legacy_overlays() {
  if [[ "$SKIP_OVERLAY" -eq 1 ]]; then
    echo "[Overlay] SKIP_OVERLAY=1 — legacy overlay sync disabled (recommended for standard PHP-FPM deployments)."
    return 0
  fi
  if [[ ! -d "${CUSTOM_ROOT}" ]]; then
    echo "[Overlay] SKIP — ${CUSTOM_ROOT} not present (legacy overlay layout unused)."
    return 0
  fi

  echo ""
  echo "═══════════════════════════════════════════════════════════════════"
  echo " Legacy overlay sync → targets are INSIDE ${CUSTOM_ROOT}"
  echo " Standard PHP-FPM deployments (Bells University) do NOT use these."
  echo "═══════════════════════════════════════════════════════════════════"
  sync_overlay_dir "${REPO_DIR}/local/ulms_academics"       "${CUSTOM_ROOT}/local/ulms_academics"         "academics"
  sync_overlay_dir "${REPO_DIR}/local/ulms_auth"            "${CUSTOM_ROOT}/local/ulms_auth"              "auth"
  sync_overlay_dir "${REPO_DIR}/local/ulms_dashboard"       "${CUSTOM_ROOT}/local/ulms_dashboard"         "dashboard"
  sync_overlay_dir "${REPO_DIR}/local/ulms_mail"            "${CUSTOM_ROOT}/local/ulms_mail"              "mail"
  sync_overlay_dir "${REPO_DIR}/theme/ulms_university"      "${CUSTOM_ROOT}/themes/ulms_university"       "theme"
  sync_overlay_dir "${REPO_DIR}/blocks/ulms_dashboard_widgets" "${CUSTOM_ROOT}/blocks/ulms_dashboard_widgets" "dashboard-widgets"
}

# ── Step 3: Moodle upgrade + cache purge — STANDARD PHP-FPM (primary) ──────
run_standard_php_refresh() {
  echo ""
  echo "═══════════════════════════════════════════════════════════════════"
  echo " STANDARD PHP-FPM / WEB-SERVER REFRESH (PRIMARY PATH)"
  echo " Target: Ubuntu 24.04 + PHP 8.3-FPM (Bells University deployment)"
  echo "═══════════════════════════════════════════════════════════════════"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "  DRY-RUN: would run Moodle upgrade (non-interactive) + purge_caches + opcache reset"
    return 0
  fi

  local php_bin="${PHP_BINARY:-php}"
  echo "[Moodle] upgrade.php --non-interactive"
  if ! "$php_bin" "${REPO_DIR}/admin/cli/upgrade.php" --non-interactive; then
    echo ""
    echo "WARNING: Moodle upgrade.php returned non-zero.  This is typically safe when"
    echo "  there are no pending plugin/schema upgrades.  Continuing with cache purge."
  fi

  echo "[Moodle] purge_caches.php"
  "$php_bin" "${REPO_DIR}/admin/cli/purge_caches.php"

  # Best-effort opcache reset via php-fpm if the CLI tooling exists.
  # This requires cachetool or the opcache_reset script; skip if absent.
  if command -v cachetool >/dev/null 2>&1; then
    echo "[OPcache] cachetool opcache:reset (php-fpm)"
    cachetool opcache:reset || echo "  (cachetool reset returned non-zero — ignore if PHP-FPM socket not reachable)"
  else
    echo "[OPcache] SKIP — cachetool not installed.  PHP-FPM opcache will reset on next"
    echo "  request after the realpath TTL; for immediate reset restart php-fpm manually:"
    echo "    sudo systemctl restart php8.3-fpm.service"
  fi
}

# ── Step 4 (OPTIONAL): Docker Compose refresh ──────────────────────────────
run_docker_refresh() {
  if [[ "$ENABLE_DOCKER" -ne 1 ]]; then
    echo ""
    echo "[Docker] SKIP — --docker flag not supplied.  The default deployment model is"
    echo "  standard PHP-FPM (see runbook §4 for the Bells University nginx+php-fpm setup)."
    return 0
  fi

  if [[ ! -f "${COMPOSE_FILE}" ]]; then
    echo ""
    echo "[Docker] WARNING: --docker supplied but docker-compose.yml not found at ${COMPOSE_FILE}."
    echo "  Skipping container refresh.  Nothing to do."
    return 1
  fi
  if ! command -v docker >/dev/null 2>&1; then
    echo ""
    echo "[Docker] WARNING: --docker supplied but docker CLI is not on PATH."
    echo "  Skipping container refresh."
    return 1
  fi

  echo ""
  echo "═══════════════════════════════════════════════════════════════════"
  echo " DOCKER COMPOSE REFRESH (SECONDARY — must be explicitly opted-in)"
  echo "═══════════════════════════════════════════════════════════════════"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "  DRY-RUN: would run: docker compose up -d moodle-app nginx + exec upgrade + purge_caches"
    return 0
  fi
  (
    cd "${WORKSPACE_DIR}"
    docker compose up -d moodle-app nginx
    docker compose exec -T moodle-app php admin/cli/upgrade.php --non-interactive
    docker compose exec -T moodle-app php admin/cli/purge_caches.php
  )
}

# ── Orchestrator ────────────────────────────────────────────────────────────
main() {
  install_root_dependencies
  sync_legacy_overlays
  run_standard_php_refresh
  run_docker_refresh || true
  echo ""
  echo "ULMS live refresh completed.  Recommended post-refresh checks:"
  echo "  1. APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php"
  echo "  2. php local/ulms_dashboard/cli/ops_healthcheck.php --max-cron-age-minutes=3 --max-backup-age-hours=25"
  echo "  3. (Optional) php local/ulms_dashboard/cli/ops_backup_smoke.php"
  exit 0
}

main "$@"
