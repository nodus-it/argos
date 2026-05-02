# shellcheck shell=bash
# lib/logging.sh — central logging helpers for the worker.
#
# All output goes to stderr so stdout stays free for structured payloads.
# LOG_LEVEL=debug -> debug+info+warn+error
# LOG_LEVEL=info  -> info+warn+error  (default)
# LOG_LEVEL=warn  -> warn+error
# LOG_LEVEL=error -> error only

_log_level_value() {
    case "${LOG_LEVEL:-info}" in
        debug) echo 0 ;;
        info)  echo 1 ;;
        warn)  echo 2 ;;
        error) echo 3 ;;
        *)     echo 1 ;;
    esac
}

# log_debug: visible only when LOG_LEVEL=debug.
log_debug() {
    [[ "$(_log_level_value)" -le 0 ]] || return 0
    echo "[DEBUG] $*" >&2
}

log_info() {
    [[ "$(_log_level_value)" -le 1 ]] || return 0
    echo "[INFO] $*" >&2
}

log_warn() {
    [[ "$(_log_level_value)" -le 2 ]] || return 0
    echo "[WARN] $*" >&2
}

# log_error: always visible.
log_error() {
    echo "[ERROR] $*" >&2
}
