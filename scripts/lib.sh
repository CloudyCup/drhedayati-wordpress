#!/usr/bin/env bash
# Shared helpers for the disposable local WordPress integration-test environment.
# Sourced by the other scripts/*.sh files — not run directly.
set -euo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$LIB_DIR/.." && pwd)"
DOCKER_DIR="$REPO_ROOT/docker"

cd "$DOCKER_DIR"

# Seed docker/.env from the example on first use (gitignored, local-only creds).
if [ ! -f .env ]; then
  cp .env.example .env
  echo "[lib] created docker/.env from docker/.env.example"
fi

# shellcheck disable=SC1091
set -a; . "$DOCKER_DIR/.env"; set +a

: "${WP_URL:=http://localhost:8080}"
: "${WP_TITLE:=Hedayati Local Test}"
: "${WP_ADMIN_USER:=admin}"
: "${WP_ADMIN_PASSWORD:=admin_local_only}"
: "${WP_ADMIN_EMAIL:=admin@hedayati.test}"

if docker compose version >/dev/null 2>&1; then
  DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DC=(docker-compose)
else
  echo "ERROR: Docker Compose not found. Install Docker Desktop / the compose plugin." >&2
  exit 127
fi

dc() { "${DC[@]}" "$@"; }

# Run a WP-CLI command in a throwaway container (entrypoint is `wp`).
wpcli() { "${DC[@]}" run --rm -T wpcli "$@"; }

# Run an arbitrary command in a throwaway wpcli container (bypasses the wp entrypoint).
wpcli_sh() { "${DC[@]}" run --rm -T --entrypoint sh wpcli -c "$*"; }

log()  { printf '\033[1;34m[%s]\033[0m %s\n' "${SCRIPT_NAME:-localtest}" "$*"; }
die()  { printf '\033[1;31m[%s] ERROR:\033[0m %s\n' "${SCRIPT_NAME:-localtest}" "$*" >&2; exit 1; }

wait_for_wordpress_files() {
  log "waiting for the WordPress container to generate wp-config.php ..."
  for _ in $(seq 1 60); do
    if dc exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
      log "wp-config.php present."
      return 0
    fi
    sleep 2
  done
  die "timed out waiting for wp-config.php"
}

ensure_up() {
  dc up -d db wordpress
  dc build wpcli
  wait_for_wordpress_files
}

ensure_installed() {
  if ! wpcli core is-installed >/dev/null 2>&1; then
    "$LIB_DIR/wp-install.sh"
  fi
}
