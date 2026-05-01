# logging.sh: Zentrale Logging-Funktionen fuer den Worker.
# Alle Ausgaben gehen auf stderr, damit stdout fuer strukturierten Output frei bleibt.
# LOG_LEVEL=debug  -> debug+info+warn+error
# LOG_LEVEL=info   -> info+warn+error  (default)
# LOG_LEVEL=warn   -> warn+error
# LOG_LEVEL=error  -> nur error

_log_level_value() {
    case "${LOG_LEVEL:-info}" in
        debug) echo 0 ;;
        info)  echo 1 ;;
        warn)  echo 2 ;;
        error) echo 3 ;;
        *)     echo 1 ;;
    esac
}

# log_debug: Gibt eine DEBUG-Meldung aus (nur bei LOG_LEVEL=debug).
# Args: $@=message
log_debug() {
    [[ "$(_log_level_value)" -le 0 ]] || return 0
    echo "[DEBUG] $*" >&2
}

# log_info: Gibt eine INFO-Meldung aus.
# Args: $@=message
log_info() {
    [[ "$(_log_level_value)" -le 1 ]] || return 0
    echo "[INFO] $*" >&2
}

# log_warn: Gibt eine WARN-Meldung aus.
# Args: $@=message
log_warn() {
    [[ "$(_log_level_value)" -le 2 ]] || return 0
    echo "[WARN] $*" >&2
}

# log_error: Gibt eine ERROR-Meldung aus (immer sichtbar).
# Args: $@=message
log_error() {
    echo "[ERROR] $*" >&2
}
