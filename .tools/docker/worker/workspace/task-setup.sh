#!/usr/bin/env bash
# task-setup.sh — prepare a task's worktree before Claude starts.
#
# Called by the workspace daemon with the worktree as working directory,
# after it wrote the task's values (database, Redis prefix, …) into .env.
# A project can bring its own steps in .argos/setup.sh (run with bash, from
# the worktree root); next comes the project's `composer setup` script (the
# Laravel skeleton ships one); otherwise the Laravel defaults run: composer
# install, an APP_KEY and the migrations. While a workspace setup is not merged yet,
# ARGOS_SETUP_REF names its branch and the steps come from there. Output goes to stdout/stderr; a non-zero
# exit marks the setup as failed (the task stays usable).
set -euo pipefail
IFS=$'\n\t'

# setup_log: one progress line the chat shows.
# Args: $1=message
setup_log() {
    printf '[setup] %s\n' "$1"
}

# setup_composer: install PHP dependencies when the project has any.
setup_composer() {
    [[ -f composer.json ]] || return 0
    command -v composer >/dev/null || { setup_log "composer not available — skipped"; return 0; }
    setup_log "composer install"
    composer install --no-interaction --no-progress --prefer-dist
}

# setup_app_key: generate an APP_KEY when .env has an empty one.
setup_app_key() {
    [[ -f artisan && -f .env ]] || return 0
    grep -qE '^APP_KEY=\s*$' .env || return 0
    setup_log "php artisan key:generate"
    php artisan key:generate --force --no-interaction
}

# setup_migrate: migrate the task's database (best-effort without one).
setup_migrate() {
    [[ -f artisan && -f vendor/autoload.php ]] || return 0
    setup_log "php artisan migrate"
    php artisan migrate --force --no-interaction || setup_log "migrations failed — Claude can look into it"
}

if [[ -f .argos/setup.sh ]]; then
    setup_log "running .argos/setup.sh"
    exec bash .argos/setup.sh
fi

if [[ -n "${ARGOS_SETUP_REF:-}" ]] && git cat-file -e "origin/${ARGOS_SETUP_REF}:.argos/setup.sh" 2>/dev/null; then
    setup_log "running .argos/setup.sh from ${ARGOS_SETUP_REF}"
    script="$(mktemp)"
    git show "origin/${ARGOS_SETUP_REF}:.argos/setup.sh" > "$script"
    trap 'rm -f "$script"' EXIT
    bash "$script"
    exit $?
fi

if [[ -f composer.json ]] && command -v composer >/dev/null && command -v jq >/dev/null \
        && jq -e '.scripts.setup' composer.json >/dev/null 2>&1; then
    setup_log "composer run-script setup"
    exec composer run-script setup --no-interaction
fi

setup_composer
setup_app_key
setup_migrate
setup_log "done"
