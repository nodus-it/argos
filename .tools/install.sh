#!/usr/bin/env bash
# Argos installer.
#
# Self-host Argos in one command:
#
#   curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/.tools/install.sh | bash
#
# Default install directory is $PWD (the directory you ran the curl from).
# Override:
#   curl … | bash -s -- --dir /opt/argos
#   ARGOS_INSTALL_DIR=/opt/argos curl … | bash
#
# Re-running the script in the same install directory updates the stack:
# downloads any newer docker-compose.yml, merges new keys from .env.example
# into .env without touching existing values, then `docker compose pull && up -d`.
#
# To layer in custom config (extra ports, labels, env), drop a
# docker-compose.override.yml next to docker-compose.yml — the installer
# never touches that file.
#
# Beta channel (latest release including pre-releases):
#   curl … | bash -s -- --beta
#   ARGOS_BETA=1 curl … | bash
# Selects the newest release tag from GitHub (including pre-releases) instead
# of the latest stable release. Also used automatically as fallback when no
# stable release exists yet.
#
# Stage channel (rolling :stage images from the develop branch):
#   curl … | bash -s -- --stage
#   ARGOS_STAGE=1 curl … | bash
# Installs the develop manifests and pins ARGOS_APP_IMAGE to the :stage tag
# that CI publishes on every develop push. Tracks unreleased work and may
# break — not for production. Re-pass --stage on update to keep tracking it.
#
# Next channel (rolling :next images from the next branch):
#   curl … | bash -s -- --next
#   ARGOS_NEXT=1 curl … | bash
# Installs the next manifests and pins ARGOS_APP_IMAGE to the :next tag that CI
# publishes on every next push. `next` is the integration line for the upcoming
# version — ahead of develop, even less stable than stage. Re-pass --next on
# update to keep tracking it.
#
# Reset (DESTRUCTIVE — wipes DB + all named volumes):
#   bash .tools/install.sh --reset            # interactive: prompts for "yes"
#   curl … | bash -s -- --reset --force        # non-interactive: --force is required

set -euo pipefail
IFS=$'\n\t'

# ── Configuration ───────────────────────────────────────────────────────────
ARGOS_REPO="${ARGOS_REPO:-nodus-it/argos}"
INSTALL_DIR="${ARGOS_INSTALL_DIR:-$PWD}"
FORCE=0
RESET=0
# ARGOS_VERSION is resolved lazily — main() calls resolve_default_version()
# when the user hasn't pinned it via env or --version. We can't do that at
# the top level because the source-guard for tests would still trigger the
# API call on every `source install.sh`.
ARGOS_VERSION="${ARGOS_VERSION:-}"

# Beta channel: resolves the newest release tag including pre-releases instead
# of the latest stable-only tag. Off by default; also activates automatically
# when no stable release exists yet (transparent fallback, with a warning).
BETA="${ARGOS_BETA:-0}"

# Stage channel: track the rolling :stage images CI publishes on every push to
# develop. Implies installing the develop manifests and pinning ARGOS_APP_IMAGE
# to the :stage tag. Off by default — tracks unreleased work and may break.
STAGE="${ARGOS_STAGE:-0}"

# The rolling pre-release image tag the stage channel pins. Derived from the
# repo so a fork's installer points at the fork's own registry.
STAGE_IMAGE="ghcr.io/${ARGOS_REPO}-app:stage"

# Next channel: track the rolling :next images CI publishes on every push to
# the next branch (the integration line for the upcoming version). Implies
# installing the next manifests and pinning ARGOS_APP_IMAGE to the :next tag.
# Off by default — ahead of develop, even less stable than stage.
NEXT="${ARGOS_NEXT:-0}"

# The rolling image tag the next channel pins.
NEXT_IMAGE="ghcr.io/${ARGOS_REPO}-app:next"

# Files the installer owns inside INSTALL_DIR.
COMPOSE_FILE="docker-compose.yml"
ENV_EXAMPLE_FILE=".env.example"
ENV_FILE=".env"
STATE_DIR=".argos-state"
# Legacy artefacts from earlier installer versions — wiped on update so they
# don't sit in the install dir confusing operators. nginx.conf is now baked
# into the app image; public/ is populated into a shared volume by the app
# entrypoint instead of bind-mounted from the host.
LEGACY_FILES=("nginx.conf")
LEGACY_DIRS=("public")

# ── Output helpers ──────────────────────────────────────────────────────────
log()     { printf '\033[1;34m▸\033[0m %s\n' "$*"; }
warn()    { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
success() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }
fail()    { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

# ASCII banner shown once at the start of each install/update run. Cyan on
# colour-capable terminals, plain on dumb terms / non-tty stdout.
print_banner() {
    local c0="" c1=""
    if [[ -t 1 && "${TERM:-dumb}" != "dumb" ]]; then
        c0=$'\033[1;36m'
        c1=$'\033[0m'
    fi
    cat <<BANNER
${c0}
       █████╗ ██████╗  ██████╗  ██████╗ ███████╗
      ██╔══██╗██╔══██╗██╔════╝ ██╔═══██╗██╔════╝
      ███████║██████╔╝██║  ███╗██║   ██║███████╗
      ██╔══██║██╔══██╗██║   ██║██║   ██║╚════██║
      ██║  ██║██║  ██║╚██████╔╝╚██████╔╝███████║
      ╚═╝  ╚═╝╚═╝  ╚═╝ ╚═════╝  ╚═════╝ ╚══════╝
${c1}             the hundred-eyed dev agent
BANNER
}

usage() {
    cat <<USAGE
Usage: install.sh [options]

Options:
  -d, --dir PATH       Install directory (default: \$PWD = $PWD)
  -v, --version REF    Git ref to install from (default: $ARGOS_VERSION)
  -b, --beta           Install the latest release including pre-releases.
                       Useful before the first stable release ships or to
                       track RC builds. Also activates automatically as a
                       transparent fallback when no stable release exists yet.
  -s, --stage          Track the rolling ':stage' images CI builds on every
                       push to the develop branch. Installs the develop
                       manifests and pins ARGOS_APP_IMAGE to the :stage tag.
                       Tracks unreleased work and may break — not for
                       production. Re-pass on update to keep tracking it.
  -n, --next           Track the rolling ':next' images CI builds on every
                       push to the next branch (the upcoming version's
                       integration line). Installs the next manifests and pins
                       ARGOS_APP_IMAGE to the :next tag. Ahead of develop, even
                       less stable than --stage. Re-pass on update to keep it.
  -r, --reset          DESTRUCTIVE: tear down the existing stack, wipe the
                       compose volumes (DB included!) and the local .env /
                       state, then run a fresh install. Requires --force when
                       stdin is not a TTY (e.g. curl | bash).
  -f, --force          Skip safety prompts (e.g. non-empty install dir,
                       --reset confirmation)
  -h, --help           Show this help

Environment overrides:
  ARGOS_INSTALL_DIR    Same as --dir
  ARGOS_VERSION        Same as --version
  ARGOS_BETA=1         Same as --beta
  ARGOS_STAGE=1        Same as --stage
  ARGOS_NEXT=1         Same as --next
  ARGOS_REPO           GitHub repo (default: nodus-it/argos)
USAGE
}

# ── Argument parsing ────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        -d|--dir)     INSTALL_DIR="$2"; shift 2;;
        -v|--version) ARGOS_VERSION="$2"; shift 2;;
        -b|--beta)    BETA=1; shift;;
        -s|--stage)   STAGE=1; shift;;
        -n|--next)    NEXT=1; shift;;
        -r|--reset)   RESET=1; shift;;
        -f|--force)   FORCE=1; shift;;
        -h|--help)    usage; exit 0;;
        *)            fail "Unknown argument: $1 (try --help)";;
    esac
done

# Stage and next both pin ARGOS_APP_IMAGE to a different rolling tag and select
# a different branch — picking both is ambiguous. Reject it early.
if [[ "$STAGE" -eq 1 && "$NEXT" -eq 1 ]]; then
    fail "Choose only one rolling channel: --stage (develop) or --next (next)."
fi

# Where the installer manifests live. Defaults to the GitHub raw URL; tests
# (and air-gapped installs) can point this at a local directory via file://.
# RAW_BASE is computed in main() after ARGOS_VERSION has been resolved.
RAW_BASE=""

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

# resolve_beta_version: Returns the most recent release tag from GitHub,
# including pre-releases. Fetches /releases (array, newest-first by GitHub's
# default sort) and takes the first entry's tag_name.
# Args: none
# Returns: tag_name on stdout, empty string when the repo has no releases or
#          the API is unreachable.
resolve_beta_version() {
    local tag
    tag="$(curl -fsSL "https://api.github.com/repos/${ARGOS_REPO}/releases" 2>/dev/null \
        | grep '"tag_name"' \
        | head -1 \
        | sed -E 's/.*"tag_name":[[:space:]]*"([^"]+)".*/\1/')" || tag=""
    printf '%s' "$tag"
}

# resolve_default_version: Returns the latest *stable* release tag from GitHub.
# Falls back to resolve_beta_version when no stable release exists yet (prints
# a warning). Returns empty only when neither endpoint yields a tag — caller
# then falls back to the develop branch.
# Args: none
# Returns: tag_name on stdout
resolve_default_version() {
    local tag
    tag="$(curl -fsSL "https://api.github.com/repos/${ARGOS_REPO}/releases/latest" 2>/dev/null \
        | grep '"tag_name"' \
        | head -1 \
        | sed -E 's/.*"tag_name":[[:space:]]*"([^"]+)".*/\1/')" || tag=""
    if [[ -z "$tag" ]]; then
        local pre_tag
        pre_tag="$(resolve_beta_version)"
        if [[ -n "$pre_tag" ]]; then
            warn "No stable release found — using latest pre-release ($pre_tag)."
            warn "Pass --beta to select pre-releases explicitly in the future."
            tag="$pre_tag"
        fi
    fi
    printf '%s' "$tag"
}

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

# Older installer versions dropped a nginx.conf file and a public/ directory
# next to docker-compose.yml. They are no longer
# part of the runtime — nginx.conf is baked into the app image, public/ is
# populated by the app entrypoint into a shared volume. Wipe them on update.
remove_legacy_artefacts() {
    local f
    for f in "${LEGACY_FILES[@]}"; do
        if [[ -e "$f" ]]; then
            rm -f "$f"
            log "Removed legacy file $f (now lives inside the app image)."
        fi
    done
    local d
    for d in "${LEGACY_DIRS[@]}"; do
        if [[ -d "$d" ]]; then
            rm -rf "$d"
            log "Removed legacy directory $d (now lives in a shared volume)."
        fi
    done
}

# ── Phases ──────────────────────────────────────────────────────────────────
download_install_files() {
    local tmp_compose tmp_env
    tmp_compose="$(mktemp)"
    tmp_env="$(mktemp)"

    log "Downloading $ARGOS_VERSION manifests from $ARGOS_REPO ..."
    download_to "${RAW_BASE}/docker-compose.yml" "$tmp_compose"
    download_to "${RAW_BASE}/.env.example"       "$tmp_env"

    # Promote: but for the compose file, refuse to overwrite if the user has
    # locally edited it (we'd silently nuke their changes otherwise).
    if [[ -f "$COMPOSE_FILE" && -f "$STATE_DIR/compose.sha256" ]]; then
        local local_sha recorded_sha
        local_sha="$(sha256_of "$COMPOSE_FILE")"
        recorded_sha="$(cat "$STATE_DIR/compose.sha256")"
        if [[ "$local_sha" != "$recorded_sha" ]]; then
            rm -f "$tmp_compose" "$tmp_env"
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
    mv "$tmp_env"     "$ENV_EXAMPLE_FILE"
    # mktemp creates files mode 600; these two don't carry secrets and need
    # to be readable / friendly to `cat`/`scp` from a non-owner. .env keeps
    # its 600 — it's set in initialise_env_file() after the secret values
    # are baked in.
    chmod 644 "$COMPOSE_FILE" "$ENV_EXAMPLE_FILE"

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

# backfill_missing_secrets: Generate values for secret-bearing keys that
# exist in $ENV_FILE but are empty. Catches the case where an update-mode
# install lands on a .env that was reset (e.g. user copied .env.example over
# it) — without this the DB container would refuse to initialise because
# MARIADB_ROOT_PASSWORD is empty. Existing non-empty values are NEVER
# overwritten, so users who pinned their own secrets keep them.
# Args: none (reads $ENV_FILE)
# Returns: 0
backfill_missing_secrets() {
    local generated=0
    if [[ -z "$(get_env_value "$ENV_FILE" APP_KEY)" ]]; then
        set_env_value "$ENV_FILE" APP_KEY "$(gen_app_key)"
        warn "APP_KEY was empty in $ENV_FILE — generated a new one."
        generated=$((generated + 1))
    fi
    local key
    for key in ADMIN_PASSWORD ARGOS_DB_PASSWORD ARGOS_DB_ROOT_PASSWORD; do
        if [[ -z "$(get_env_value "$ENV_FILE" "$key")" ]]; then
            set_env_value "$ENV_FILE" "$key" "$(gen_password)"
            warn "$key was empty in $ENV_FILE — generated a new one."
            generated=$((generated + 1))
        fi
    done
    if [[ $generated -gt 0 ]]; then
        chmod 600 "$ENV_FILE"
        warn "If the DB volume was already initialised with different credentials,"
        warn "the app will fail to authenticate. Wipe argos-db / argos-data volumes"
        warn "to start fresh, or restore the previous .env from backup."
    fi
}

# apply_stage_image: Pin ARGOS_APP_IMAGE in $ENV_FILE to the rolling :stage
# tag so `docker compose pull` fetches the develop image instead of the release
# default. Called only on the stage channel; like --beta the choice is
# per-invocation — re-pass --stage on update to keep tracking it.
# Args: none (writes $ENV_FILE, reads $STAGE_IMAGE)
# Returns: 0
apply_stage_image() {
    set_env_value "$ENV_FILE" ARGOS_APP_IMAGE "$STAGE_IMAGE"
    log "Pinned ARGOS_APP_IMAGE=$STAGE_IMAGE (stage channel)."
}

# apply_next_image: Pin ARGOS_APP_IMAGE in $ENV_FILE to the rolling :next tag so
# `docker compose pull` fetches the next image instead of the release default.
# Called only on the next channel; like --stage the choice is per-invocation —
# re-pass --next on update to keep tracking it.
# Args: none (writes $ENV_FILE, reads $NEXT_IMAGE)
# Returns: 0
apply_next_image() {
    set_env_value "$ENV_FILE" ARGOS_APP_IMAGE "$NEXT_IMAGE"
    log "Pinned ARGOS_APP_IMAGE=$NEXT_IMAGE (next channel)."
}

# reset_stack: Tear down the compose stack (containers + named volumes),
# remove the local .env and state files, and clean up legacy artefacts so the
# next install path is "fresh". Idempotent — runs cleanly even when nothing
# of the stack exists yet (compose down on a missing project is a no-op).
# Only touches volumes defined in $COMPOSE_FILE (compose's own scope) — never
# unrelated volumes from other compose projects the user may run.
# Args: none (acts on cwd = $INSTALL_DIR)
# Returns: 0 on success
reset_stack() {
    if [[ -f "$COMPOSE_FILE" ]]; then
        log "Tearing down stack and removing its named volumes ..."
        # --volumes: drop named volumes declared in this compose file (DB!)
        # --remove-orphans: catch services renamed/dropped between versions
        docker compose -f "$COMPOSE_FILE" down --volumes --remove-orphans \
            || warn "compose down reported errors — continuing reset."
    else
        log "No $COMPOSE_FILE to tear down — skipping compose down."
    fi

    # Wipe local install state so the next run takes the fresh-install path
    # (which regenerates secrets etc.) instead of update.
    rm -f "$ENV_FILE" "$ENV_EXAMPLE_FILE"
    rm -rf "$STATE_DIR"
    remove_legacy_artefacts

    success "Reset complete."
}

# confirm_reset: Block destructive --reset behind an explicit confirmation.
# Interactive TTY: prompt for "yes". Non-TTY (curl | bash): require --force,
# since a piped install can't read user input safely.
confirm_reset() {
    if [[ "$FORCE" -eq 1 ]]; then
        return 0
    fi
    if [[ ! -t 0 ]]; then
        fail "--reset is destructive (DB volumes are wiped). Pass --force to confirm in non-interactive mode."
    fi
    cat <<MSG >&2

! --reset will tear down the Argos compose stack at:
    $INSTALL_DIR
  and DELETE its named volumes — including all database data.
  This cannot be undone.

MSG
    local answer
    read -r -p "Type 'yes' to proceed, anything else to abort: " answer
    if [[ "$answer" != "yes" ]]; then
        fail "Aborted by user."
    fi
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
    local port admin url channel
    port="$(get_env_value "$ENV_FILE" ARGOS_PORT)"
    admin="$(get_env_value "$ENV_FILE" ADMIN_PASSWORD)"
    url="$(get_env_value "$ENV_FILE" APP_URL)"
    [[ -z "$url" ]] && url="http://localhost:${port:-8080}"
    channel="release"
    [[ "$BETA"  -eq 1 ]] && channel="beta (pre-release)"
    [[ "$STAGE" -eq 1 ]] && channel="stage (rolling develop)"
    [[ "$NEXT"  -eq 1 ]] && channel="next (rolling integration)"

    local b0="" b1=""
    if [[ -t 1 && "${TERM:-dumb}" != "dumb" ]]; then
        b0=$'\033[1;32m'
        b1=$'\033[0m'
    fi

    cat <<SUMMARY

${b0}╔══════════════════════════════════════════════════════════════════╗
║                      Argos is up and running                     ║
╚══════════════════════════════════════════════════════════════════╝${b1}

  URL:       $url

  Login
    Email:     admin@argos.local
    Password:  $admin

  Install
    Directory: $INSTALL_DIR
    Channel:   $channel
    Version:   $ARGOS_VERSION

  Useful commands
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE logs -f
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE down
    docker compose -f $INSTALL_DIR/$COMPOSE_FILE restart

  Update
    $0 --dir $INSTALL_DIR

  Change your admin password under Profile after first login.

SUMMARY
}

# ── Main ────────────────────────────────────────────────────────────────────
main() {
    print_banner
    preflight

    if [[ -z "$ARGOS_VERSION" ]]; then
        if [[ "$STAGE" -eq 1 ]]; then
            log "Stage channel: tracking the rolling :stage images from develop."
            ARGOS_VERSION="develop"
        elif [[ "$NEXT" -eq 1 ]]; then
            log "Next channel: tracking the rolling :next images from next."
            ARGOS_VERSION="next"
        elif [[ "$BETA" -eq 1 ]]; then
            log "Beta channel: resolving latest release including pre-releases …"
            ARGOS_VERSION="$(resolve_beta_version)"
            if [[ -z "$ARGOS_VERSION" ]]; then
                warn "No release found (including pre-releases) — falling back to the develop branch."
                warn "Pin a specific ref with --version <tag|branch> or ARGOS_VERSION=…"
                ARGOS_VERSION="develop"
            else
                log "Beta channel: selected $ARGOS_VERSION"
            fi
        else
            ARGOS_VERSION="$(resolve_default_version)"
            if [[ -z "$ARGOS_VERSION" ]]; then
                warn "No published release found — falling back to the develop branch."
                warn "Pin a specific ref with --version <tag|branch> or ARGOS_VERSION=…"
                ARGOS_VERSION="develop"
            fi
        fi
    fi
    RAW_BASE="${ARGOS_RAW_BASE:-https://raw.githubusercontent.com/${ARGOS_REPO}/${ARGOS_VERSION}/.tools/docker}"

    mkdir -p "$INSTALL_DIR"
    cd "$INSTALL_DIR"

    if [[ "$RESET" -eq 1 ]]; then
        confirm_reset
        reset_stack
    fi

    local mode="install"
    if [[ -f "$STATE_DIR/VERSION" ]]; then
        mode="update"
    fi

    if [[ "$BETA" -eq 1 ]]; then
        log "Beta channel selected (latest release including pre-releases)."
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
        backfill_missing_secrets
    fi

    if [[ "$STAGE" -eq 1 ]]; then
        apply_stage_image
    elif [[ "$NEXT" -eq 1 ]]; then
        apply_next_image
    fi

    remove_legacy_artefacts

    bring_stack_up
    [[ "$mode" == "install" ]] && print_summary
    success "Done."
}

# Run main only when invoked directly. When the script is sourced (e.g. by
# bats tests calling individual helpers in isolation) this guard prevents
# main from firing and trying to talk to docker.
if [[ "${BASH_SOURCE[0]:-}" == "$0" || -z "${BASH_SOURCE[0]:-}" ]]; then
    main "$@"
fi
