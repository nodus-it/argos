#!/usr/bin/env bash
# worker-entrypoint.sh — Phase-Dispatcher im Container.
#
# Wird vom Compose-Service `worker` als ENTRYPOINT aufgerufen mit
#   <phase> <task_id>
# fuer Phase-Aufrufe oder mit beliebigen Commands fuer ad-hoc Operationen
# (echo, bash, etc.). Siehe IMPLEMENTATION.md Abschnitt 4.

set -euo pipefail
IFS=$'\n\t'

# Pfad zum installierten Code im Image.
AGENT_SHARE="${AGENT_SHARE:-/usr/local/share/agent}"
LIB_DIR="${LIB_DIR:-$AGENT_SHARE/lib}"
PHASES_DIR="${PHASES_DIR:-$AGENT_SHARE/phases}"
PROMPTS_DIR="${PROMPTS_DIR:-$AGENT_SHARE/prompts}"
SCHEMAS_DIR="${SCHEMAS_DIR:-$AGENT_SHARE/schemas}"
RUNTIME_DIR="${RUNTIME_DIR:-/workspace/.agent/runtime}"
STATE_FILE="${STATE_FILE:-/workspace/.agent/state.json}"
LOCK_FILE="${LOCK_FILE:-/workspace/.agent/.lock}"

export PHASES_DIR PROMPTS_DIR SCHEMAS_DIR RUNTIME_DIR STATE_FILE LOCK_FILE

usage() {
    cat <<'USAGE' >&2
Usage:
  worker-entrypoint.sh <phase> <task_id>
  worker-entrypoint.sh shell           — interaktive bash, /workspace gemountet
  worker-entrypoint.sh -h | --help

Bekannte Phasen:
  concept | implement | diff | push | commit-message
USAGE
}

# _ep_die: Echo + exit. Fuer den Pre-Dispatch-Pfad bevor lib/error.sh gesourced ist.
_ep_die() {
    local code="$1"; shift
    [[ $# -gt 0 ]] && echo "Error: $*" >&2
    exit "$code"
}

# _ep_load_libs: Sourcet die noetigen Lib-Files. Reihenfolge wichtig (deps).
_ep_load_libs() {
    # shellcheck disable=SC1091
    source "$LIB_DIR/logging.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/error.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/result.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/credentials.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/state.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/lock.sh"
    # shellcheck disable=SC1091
    source "$LIB_DIR/prompts.sh"
    # shellcheck disable=SC1091
    source "$PHASES_DIR/registry.sh"
}

# _ep_validate_env: Prueft Env-Variablen je nach Phase.
# Args: $1=phase
# Returns: 0 wenn ok, sonst exit-code.
_ep_validate_env() {
    local phase="$1"
    case "$phase" in
        concept|implement|push)
            [[ -n "${REPO_URL:-}" ]]    || { echo "Error: REPO_URL not set" >&2; return 2; }
            [[ -n "${REPO_TOKEN:-}" ]]  || { echo "Error: REPO_TOKEN not set" >&2; return 2; }
            [[ -n "${BASE_BRANCH:-}" ]] || { echo "Error: BASE_BRANCH not set" >&2; return 2; }
            [[ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]] \
                || { echo "Error: CLAUDE_CODE_OAUTH_TOKEN not set" >&2; return 3; }
            ;;
        diff)
            [[ -n "${BASE_BRANCH:-}" ]] || { echo "Error: BASE_BRANCH not set" >&2; return 2; }
            ;;
        commit-message)
            [[ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]] \
                || { echo "Error: CLAUDE_CODE_OAUTH_TOKEN not set" >&2; return 3; }
            ;;
    esac
    [[ -d /workspace ]] || { echo "Error: /workspace not mounted" >&2; return 1; }
    return 0
}

# _ep_init_state: Erstellt state.json falls noch nicht da.
_ep_init_state() {
    local task_id="$1"
    if [[ ! -f "$STATE_FILE" ]]; then
        log_info "init: erstelle $STATE_FILE"
        state_init "$task_id" "${REPO_URL:-}" "${BASE_BRANCH:-}"
    fi
}

# _ep_dispatch_phase: Komplettes Phase-Lifecycle: Lock, State, Run, Result.
# Args: $1=phase, $2=task_id
# Returns: Exit-Code der Phase.
_ep_dispatch_phase() {
    local phase="$1"
    local task_id="$2"

    _ep_init_state "$task_id"

    if ! state_validate; then
        _ep_die "$EXIT_GENERAL" "state.json invalid (siehe oben)"
    fi

    # Lock setzen
    if ! lock_acquire "$phase"; then
        return "$EXIT_LOCK"
    fi

    # Trap fuer Cleanup
    local phase_exit=1
    # shellcheck disable=SC2064
    trap "_ep_on_exit '$phase' '$task_id'" EXIT
    trap '_ep_on_signal' INT TERM

    # Iteration anlegen — PHASE_FLAGS muss valides JSON sein (default '{}')
    local flags_json="${PHASE_FLAGS:-}"
    [[ -z "$flags_json" ]] && flags_json='{}'
    ITERATION="$(state_add_iteration "$phase" "$flags_json")"
    export ITERATION
    log_info "phase $phase: iteration $ITERATION started"

    # Phase laden
    if ! phase_load "$phase"; then
        state_update_iteration "$phase" "$ITERATION" failed 1 "phase_load failed"
        return 1
    fi

    # Vorbedingungen
    local pre_func="phase_${phase//-/_}_preconditions"
    if declare -F "$pre_func" >/dev/null; then
        local pre_exit=0
        $pre_func || pre_exit=$?
        if (( pre_exit != 0 )); then
            state_update_iteration "$phase" "$ITERATION" failed "$pre_exit" "preconditions failed"
            return "$pre_exit"
        fi
    fi

    # Phase ausfuehren
    local run_func="phase_${phase//-/_}_run"
    if ! declare -F "$run_func" >/dev/null; then
        state_update_iteration "$phase" "$ITERATION" failed 1 "run-function $run_func not found"
        return 1
    fi

    set +e
    $run_func
    phase_exit=$?
    set -e

    # Status aus exit-code ableiten
    local status err
    case "$phase_exit" in
        0) status=completed ;;
        4) status=quality_gate_failed ;;
        5) status=no_changes ;;
        *) status=failed; err="exit $phase_exit" ;;
    esac
    state_update_iteration "$phase" "$ITERATION" "$status" "$phase_exit" "${err:-}"

    return "$phase_exit"
}

# _ep_on_exit: Trap fuer EXIT — Lock release, Logging.
_ep_on_exit() {
    local phase="$1"
    local task_id="$2"
    lock_release || true
    log_debug "exit: phase=$phase task=$task_id"
}

# _ep_on_signal: Trap fuer INT/TERM — Lock release + state-update.
_ep_on_signal() {
    log_warn "signal received — releasing lock and exiting"
    lock_release || true
    exit 130
}

main() {
    local arg="${1:-}"

    # Write task description into the well-known path if passed as env var.
    # This replaces the old file bind-mount from the host filesystem.
    if [[ -n "${TASK_DESCRIPTION:-}" ]]; then
        mkdir -p /run/agent
        printf '%s' "$TASK_DESCRIPTION" > /run/agent/description.md
    fi

    case "$arg" in
        ""|-h|--help|help)
            usage
            exit 0
            ;;
        shell)
            shift
            exec bash "$@"
            ;;
        concept|implement|diff|push|commit-message)
            local phase="$arg"
            local task_id="${2:-${TASK_ID:-}}"
            if [[ -z "$task_id" ]]; then
                _ep_die 64 "task_id missing (arg #2 or env TASK_ID)"
            fi
            export TASK_ID="$task_id"

            _ep_load_libs
            _ep_validate_env "$phase" || exit $?
            _ep_dispatch_phase "$phase" "$task_id"
            exit $?
            ;;
        *)
            # Catchall: arbitrary command via exec
            exec "$@"
            ;;
    esac
}

main "$@"
