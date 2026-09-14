#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_DIR="$(cd "${REPO_DIR}/.." && pwd)"
COMPOSE_FILE="${WORKSPACE_DIR}/docker-compose.yml"
CUSTOM_ROOT="${WORKSPACE_DIR}/custom"

resolve_composer_command() {
  if command -v composer >/dev/null 2>&1; then
    echo "composer"
    return
  fi

  if [[ -f "${REPO_DIR}/composer.phar" ]]; then
    echo "php ${REPO_DIR}/composer.phar"
    return
  fi

  return 1
}

run_composer_install() {
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
    return
  fi

  php "${REPO_DIR}/composer.phar" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
}

install_root_dependencies() {
  if [[ ! -f "${REPO_DIR}/composer.json" || ! -f "${REPO_DIR}/composer.lock" ]]; then
    echo "No composer.json/composer.lock found; skipping Composer install"
    return
  fi

  if ! resolve_composer_command >/dev/null; then
    echo "Composer is required to install locked production dependencies."
    echo "Install Composer globally or place composer.phar in ${REPO_DIR}."
    return 1
  fi

  echo "Installing locked production Composer dependencies"
  (
    cd "${REPO_DIR}"
    run_composer_install
  )
}

sync_overlay_dir() {
  local source_dir="$1"
  local target_dir="$2"

  if [[ ! -d "${source_dir}" ]]; then
    echo "Skipping missing source: ${source_dir}"
    return
  fi

  mkdir -p "${target_dir}"
  rsync -a --delete "${source_dir}/" "${target_dir}/"
}

sync_legacy_overlays() {
  if [[ ! -d "${CUSTOM_ROOT}" ]]; then
    return
  fi

  echo "Synchronizing legacy runtime overlay directories"
  sync_overlay_dir "${REPO_DIR}/local/ulms_academics" "${CUSTOM_ROOT}/local/ulms_academics"
  sync_overlay_dir "${REPO_DIR}/local/ulms_auth" "${CUSTOM_ROOT}/local/ulms_auth"
  sync_overlay_dir "${REPO_DIR}/local/ulms_dashboard" "${CUSTOM_ROOT}/local/ulms_dashboard"
  sync_overlay_dir "${REPO_DIR}/local/ulms_mail" "${CUSTOM_ROOT}/local/ulms_mail"
  sync_overlay_dir "${REPO_DIR}/theme/ulms_university" "${CUSTOM_ROOT}/themes/ulms_university"
  sync_overlay_dir "${REPO_DIR}/blocks/ulms_dashboard_widgets" "${CUSTOM_ROOT}/blocks/ulms_dashboard_widgets"
}

run_docker_refresh() {
  if [[ ! -f "${COMPOSE_FILE}" ]]; then
    echo "No docker-compose.yml found at ${COMPOSE_FILE}; skipping container refresh"
    return
  fi

  if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is not available; skipping container refresh"
    return
  fi

  echo "Refreshing live Moodle services"
  (
    cd "${WORKSPACE_DIR}"
    docker compose up -d moodle-app nginx
    docker compose exec -T moodle-app php admin/cli/upgrade.php --non-interactive
    docker compose exec -T moodle-app php admin/cli/purge_caches.php
  )
}

main() {
  install_root_dependencies
  sync_legacy_overlays
  run_docker_refresh
  echo "ULMS live refresh completed"
}

main "$@"
