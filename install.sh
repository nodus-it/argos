#!/usr/bin/env bash
# Argos installer.
#
# Self-host Argos in one command:
#
#   curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/install.sh | bash
#
# Default install directory is $PWD (the directory you ran the curl from).
# Override:
#   curl … | bash -s -- --dir /opt/argos
#   ARGOS_INSTALL_DIR=/opt/argos curl … | bash
#
# Re-running the script in the same install directory updates the stack:
# downloads any newer docker-compose.yml / nginx.conf, merges new keys from
# .env.example into .env without touching existing values, refreshes the
# extracted public/ assets, then `docker compose pull && up -d`.
#
# To layer in custom config (extra ports, labels, env), drop a
# docker-compose.override.yml next to docker-compose.yml — the installer
# never touches that file.

set -euo pipefail
IFS=$'\n\t'

# ── Configuration ───────────────────────────────────────────────────────────
ARGOS_REPO="${ARGOS_REPO:-nodus-it/argos}"
ARGOS_VERSION="${ARGOS_VERSION:-develop}"
INSTALL_DIR="${ARGOS_INSTALL_DIR:-$PWD}"
FORCE=0

# Files the installer owns inside INSTALL_DIR.
COMPOSE_FILE="docker-compose.yml"
NGINX_FILE="nginx.conf"
ENV_EXAMPLE_FILE=".env.example"
ENV_FILE=".env"
STATE_DIR=".argos-state"
PUBLIC_DIR="public"

# ── Output helpers ──────────────────────────────────────────────────────────
log()     { printf '\033[1;34m▸\033[0m %s\n' "$*"; }
warn()    { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
success() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }
fail()    { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<USAGE
Usage: install.sh [options]

Options:
  -d, --dir PATH       Install directory (default: \$PWD = $PWD)
  -v, --version REF    Git ref to install from (default: $ARGOS_VERSION)
  -f, --force          Skip safety prompts (e.g. non-empty install dir)
  -h, --help           Show this help

Environment overrides:
  ARGOS_INSTALL_DIR    Same as --dir
  ARGOS_VERSION        Same as --version
  ARGOS_REPO           GitHub repo (default: nodus-it/argos)
USAGE
}

# ── Argument parsing ────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        -d|--dir)     INSTALL_DIR="$2"; shift 2;;
        -v|--version) ARGOS_VERSION="$2"; shift 2;;
        -f|--force)   FORCE=1; shift;;
        -h|--help)    usage; exit 0;;
        *)            fail "Unknown argument: $1 (try --help)";;
    esac
done

# Where the installer manifests live. Defaults to the GitHub raw URL; tests
# (and air-gapped installs) can point this at a local directory via file://.
RAW_BASE="${ARGOS_RAW_BASE:-https://raw.githubusercontent.com/${ARGOS_REPO}/${ARGOS_VERSION}/installer}"

# ── Pre-flight checks ───────────────────────────────────────────────────────
require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || fail "$1 not found in PATH — please install it first."
}

preflight() {
    require_command curl
    require_command docker
    require_command sha256sum
    require_command openssl

    # docker compose v2 is `docker compose`, not `docker-compose`.
    if ! docker compose version >/dev/null 2>&1; then
        fail "Docker Compose v2 not available. Install Docker Engine 20.10+ with the compose plugin."
    fi

    if ! docker info >/dev/null 2>&1; then
        fail "Cannot talk to dockerd. Is the daemon running and your user in the docker group?"
    fi
}

# ── Helpers ─────────────────────────────────────────────────────────────────
download_to() {
    local url="$1" dest="$2"
    curl -fsSL "$url" -o "$dest" \
        || fail "Failed to download $url"
}

sha256_of() {
    sha256sum "$1" | awk '{print $1}'
}

gen_password() {
    # 32 alphanumeric chars — avoids shell-quoting hazards in compose env.
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 32
}

gen_app_key() {
    printf 'base64:%s' "$(openssl rand -base64 32)"
}

# Replace `KEY=...` in a file with `KEY=$value` (idempotent).
set_env_value() {
    local file="$1" key="$2" value="$3"
    # Use a temp file to keep things atomic and avoid in-place sed quoting issues.
    local tmp
    tmp="$(mktemp)"
    awk -v k="$key" -v v="$value" '
        BEGIN { found = 0 }
        $0 ~ "^"k"=" { print k"="v; found = 1; next }
        { print }
        END { if (!found) print k"="v }
    ' "$file" > "$tmp"
    mv "$tmp" "$file"
}

# Read a value from a KEY=VALUE file, stripping surrounding quotes.
get_env_value() {
    local file="$1" key="$2"
    awk -F= -v k="$key" '$1 == k { sub("^"k"=", ""); gsub(/^"|"$/, ""); print; exit }' "$file"
}

# Merge any KEY=... lines from .env.example into .env that don't yet exist
# in .env. Existing values are never overwritten. New keys land at the bottom
# with their .env.example default (which is often empty — user fills in).
merge_env_keys() {
    local example="$1" target="$2"
    local added=0
    while IFS= read -r line; do
        # Skip comments and blanks.
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// /}" ]] && continue
        # KEY=anything — capture KEY.
        [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)= ]] || continue
        local key="${BASH_REMATCH[1]}"
        if ! grep -qE "^${key}=" "$target"; then
            printf '%s\n' "$line" >> "$target"
            log "Added new key from upstream: $key"
            added=$((added + 1))
        fi
    done < "$example"
    if [[ $added -eq 0 ]]; then
        log "No new keys to merge."
    fi
}

# Pull /app/public out of the app image into $INSTALL_DIR/public so nginx
# can serve static assets without a source bind mount. `docker cp` writes
# as the calling user, no root-owned files in the install dir.
extract_public_assets() {
    local image="$1"
    log "Extracting static assets from $image ..."
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        docker pull "$image" >/dev/null \
            || fail "Image $image is not available locally and could not be pulled."
    fi
    local cid
    cid="$(docker create "$image")"
    # shellcheck disable=SC2064  # capture cid now, not at trap-fire time.
    trap "docker rm '$cid' >/dev/null 2>&1 || true" RETURN
    rm -rf "$PUBLIC_DIR"
    mkdir -p "$PUBLIC_DIR"
    docker cp "$cid:/app/public/." "$PUBLIC_DIR/"
    docker rm "$cid" >/dev/null
    trap - RETURN
}

# ── Phases ──────────────────────────────────────────────────────────────────
download_install_files() {
    local tmp_compose tmp_nginx tmp_env
    tmp_compose="$(mktemp)"
    tmp_nginx="$(mktemp)"
    tmp_env="$(mktemp)"

    log "Downloading $ARGOS_VERSION manifests from $ARGOS_REPO ..."
    download_to "${RAW_BASE}/docker-compose.yml" "$tmp_compose"
    download_to "${RAW_BASE}/nginx.conf"         "$tmp_nginx"
    download_to "${RAW_BASE}/.env.example"       "$tmp_env"

    # Promote: but for the compose file, refuse to overwrite if the user has
    # locally edited it (we'd silently nuke their changes otherwise).
    if [[ -f "$COMPOSE_FILE" && -f "$STATE_DIR/compose.sha256" ]]; then
        local local_sha recorded_sha
        local_sha="$(sha256_of "$COMPOSE_FILE")"
        recorded_sha="$(cat "$STATE_DIR/compose.sha256")"
        if [[ "$local_sha" != "$recorded_sha" ]]; then
            rm -f "$tmp_compose" "$tmp_nginx" "$tmp_env"
            cat <<MSG >&2

✗ $COMPOSE_FILE has been modified locally since the installer wrote it.

  The installer refuses to overwrite your changes. To layer in custom
  config that survives updates, move your edits into:

      $INSTALL_DIR/docker-compose.override.yml

  Compose merges the override file automatically and the installer never
  touches it. Once $COMPOSE_FILE is back to its installer-shipped state,
  re-run install.sh to pick up the upstream version.

MSG
            exit 1
        fi
    fi

    mv "$tmp_compose" "$COMPOSE_FILE"
    mv "$tmp_nginx"   "$NGINX_FILE"
    mv "$tmp_env"     "$ENV_EXAMPLE_FILE"
    # mktemp creates files mode 600; these three don't carry secrets and need
    # to be readable by the nginx container's worker process (and friendly to
    # `cat`/`scp` from a non-owner). .env keeps its 600 — it's set in
    # initialise_env_file() after the secret values are baked in.
    chmod 644 "$COMPOSE_FILE" "$NGINX_FILE" "$ENV_EXAMPLE_FILE"

    mkdir -p "$STATE_DIR"
    sha256_of "$COMPOSE_FILE" > "$STATE_DIR/compose.sha256"
    printf '%s\n' "$ARGOS_VERSION" > "$STATE_DIR/VERSION"
}

initialise_env_file() {
    cp "$ENV_EXAMPLE_FILE" "$ENV_FILE"
    set_env_value "$ENV_FILE" APP_KEY                "$(gen_app_key)"
    set_env_value "$ENV_FILE" ADMIN_PASSWORD         "$(gen_password)"
    set_env_value "$ENV_FILE" ARGOS_DB_PASSWORD      "$(gen_password)"
    set_env_value "$ENV_FILE" ARGOS_DB_ROOT_PASSWORD "$(gen_password)"
    chmod 600 "$ENV_FILE"
    success "Wrote $ENV_FILE with generated secrets (mode 600)."
}

bring_stack_up() {
    log "Pulling images ..."
    # --ignore-pull-failures: lets pre-pulled-or-local-only images through
    # (CI bakes images locally; some self-host setups stage images out-of-band).
    docker compose pull --quiet --ignore-pull-failures

    log "Starting Argos ..."
    docker compose up -d --remove-orphans
}

print_summary() {
    local port admin url
    port="$(get_env_value "$ENV_FILE" ARGOS_PORT)"
    admin="$(get_env_value "$ENV_FILE" ADMIN_PASSWORD)"
    url="$(get_env_value "$ENV_FILE" APP_URL)"
    [[ -z "$url" ]] && url="http://localhost:${port:-8080}"

    cat <<SUMMARY

$(success "Argos is running at $url")

  Admin password: $admin
  Install dir:    $INSTALL_DIR
  Version:        $ARGOS_VERSION

  Useful commands:
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE logs -f
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE down
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE restart

  Update:
    $0 --dir $INSTALL_DIR

SUMMARY
}

# ── Main ────────────────────────────────────────────────────────────────────
main() {
    preflight

    mkdir -p "$INSTALL_DIR"
    cd "$INSTALL_DIR"

    local mode="install"
    if [[ -f "$STATE_DIR/VERSION" ]]; then
        mode="update"
    fi

    if [[ "$mode" == "install" ]]; then
        # Safety: refuse to dump files into a non-empty cwd unless the user
        # forces it, since we'd shadow whatever's already there.
        if [[ "$FORCE" -eq 0 ]]; then
            shopt -s nullglob dotglob
            local existing=("$INSTALL_DIR"/*)
            shopt -u nullglob dotglob
            # Filter out the state dir if somehow already present.
            local conflicts=()
            for f in "${existing[@]}"; do
                local name="${f##*/}"
                case "$name" in
                    "$STATE_DIR") continue;;
                esac
                conflicts+=("$f")
            done
            if [[ ${#conflicts[@]} -gt 0 ]]; then
                cat <<MSG >&2
✗ Install directory is not empty:
    $INSTALL_DIR

  Found ${#conflicts[@]} existing entr$([[ ${#conflicts[@]} -eq 1 ]] && echo "y" || echo "ies"), e.g. ${conflicts[0]##*/}

  Either pick an empty directory:
    $0 --dir ./argos

  …or pass --force to install anyway (existing files with the same names
  as installer-managed files will be overwritten).
MSG
                exit 1
            fi
        fi

        log "Fresh install in $INSTALL_DIR (version $ARGOS_VERSION)"
        download_install_files
        initialise_env_file
    else
        log "Updating $INSTALL_DIR (was: $(cat "$STATE_DIR/VERSION"), now: $ARGOS_VERSION)"
        download_install_files
        merge_env_keys "$ENV_EXAMPLE_FILE" "$ENV_FILE"
    fi

    # Resolve the app image from .env so we can extract assets out of it.
    local app_image
    app_image="$(get_env_value "$ENV_FILE" ARGOS_APP_IMAGE)"
    [[ -n "$app_image" ]] || fail "ARGOS_APP_IMAGE is empty in $ENV_FILE"
    extract_public_assets "$app_image"

    bring_stack_up
    [[ "$mode" == "install" ]] && print_summary
    success "Done."
}

main "$@"
