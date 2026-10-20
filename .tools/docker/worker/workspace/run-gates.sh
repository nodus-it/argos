#!/usr/bin/env bash
# run-gates.sh — run the worker's quality gates inside a workspace container.
#
# Called by the workspace daemon; reuses worker/lib/quality.sh unchanged so
# workspaces and tasks judge code the same way.
#
# Usage:
#   run-gates.sh --run <run-id>                 gates JSON on stdout
#   run-gates.sh --baseline                     composer install + pest/phpstan baselines
#   run-gates.sh --fix-prompt <gate> <run-id>   fix prompt on stdout
#   run-gates.sh --log-path <gate> <run-id>     path of a gate's log
#
# The gates read and write /workspace (hard-wired in quality.sh). Unlike the
# implement phase this script does not seed .env or public/hot: the workspace
# is the person's working tree and its preview must keep working.
set -euo pipefail
IFS=$'\n\t'

lib_dir="${ARGOS_AGENT_LIB_DIR:-/usr/local/share/agent/lib}"

# shellcheck source=../../../../worker/lib/logging.sh
source "${lib_dir}/logging.sh"
# shellcheck source=../../../../worker/lib/quality.sh
source "${lib_dir}/quality.sh"

# gates_prepare: create the log directory and keep it out of git.
gates_prepare() {
    mkdir -p /workspace/.agent/logs
    if [[ -d /workspace/.git/info ]] || mkdir -p /workspace/.git/info 2>/dev/null; then
        grep -qxF '.agent/' /workspace/.git/info/exclude 2>/dev/null \
            || echo '.agent/' >> /workspace/.git/info/exclude
    fi
}

# gates_install_deps: install composer dependencies of a fresh clone, so the
# baseline (and Claude) can run tests. Best-effort; skipped when vendor/
# exists or the project has no composer.json.
gates_install_deps() {
    [[ -f /workspace/composer.json && ! -f /workspace/vendor/autoload.php ]] || return 0
    command -v composer >/dev/null || return 0
    log_info "workspace: composer install"
    (cd /workspace && composer install --no-interaction --no-progress --prefer-dist) \
        &> /workspace/.agent/logs/composer-install.log || log_warn "workspace: composer install failed (see .agent/logs/composer-install.log)"
    return 0
}

# gates_safe_id: run ids end up in file names — allow only [A-Za-z0-9_-].
gates_safe_id() {
    local id="${1:-}"
    if [[ ! "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]]; then
        echo "run-gates: invalid run id" >&2
        exit 2
    fi
    printf '%s' "$id"
}

# gates_safe_gate: only the gates quality.sh knows.
gates_safe_gate() {
    case "${1:-}" in
        artisan|pint|pest|phpunit|phpstan|migrations|debug_code|test_presence) printf '%s' "$1" ;;
        *) echo "run-gates: unknown gate" >&2; exit 2 ;;
    esac
}

mode="${1:-}"
case "$mode" in
    --run)
        run_id="$(gates_safe_id "${2:-}")"
        gates_prepare
        quality_gates_run "$run_id"
        ;;
    --baseline)
        gates_prepare
        gates_install_deps
        quality_pest_baseline_capture
        quality_phpstan_baseline_capture
        ;;
    --fix-prompt)
        gate="$(gates_safe_gate "${2:-}")"
        run_id="$(gates_safe_id "${3:-}")"
        quality_gate_fix_prompt "$gate" "$(quality_gate_log_path "$gate" "$run_id")"
        ;;
    --log-path)
        gate="$(gates_safe_gate "${2:-}")"
        run_id="$(gates_safe_id "${3:-}")"
        quality_gate_log_path "$gate" "$run_id"
        ;;
    *)
        echo "usage: run-gates.sh --run <id> | --baseline | --fix-prompt <gate> <id> | --log-path <gate> <id>" >&2
        exit 2
        ;;
esac
