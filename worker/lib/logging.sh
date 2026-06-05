# shellcheck shell=bash
# lib/logging.sh — central logging helpers for the worker.
#
# All output goes to stderr so stdout stays free for structured payloads.
# Errors are reported via die() (lib/error.sh), not a log level.
# LOG_LEVEL=debug -> debug+info+warn
# LOG_LEVEL=info  -> info+warn  (default)
# LOG_LEVEL=warn  -> warn only
# LOG_LEVEL=error -> silent

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

# log_scrub: redact known token patterns from stdin → stdout.
# Streaming-fähig (line-buffered via `sed -u`), für stream-json Pipelines geeignet.
# Patterns: Anthropic OAuth/API keys, GitHub PATs, GitLab PATs, oauth2:<tok>@-URLs,
# Authorization: Bearer/Basic Header. Defensiv — auch wenn REPO_TOKEN vor dem
# Claude-Aufruf aus dem Env genommen wird, fängt das versehentlich geleakte
# Tokens aus anderen Quellen (Logs, Tool-Inputs) noch ab.
log_scrub() {
    sed -u -E \
        -e 's/sk-ant-oat01-[A-Za-z0-9_-]+/[REDACTED:claude-oauth]/g' \
        -e 's/sk-ant-api03-[A-Za-z0-9_-]+/[REDACTED:claude-api]/g' \
        -e 's/gh[psour]_[A-Za-z0-9]+/[REDACTED:github-pat]/g' \
        -e 's/glpat-[A-Za-z0-9_-]+/[REDACTED:gitlab-pat]/g' \
        -e 's#oauth2:[^@[:space:]"]+@#oauth2:[REDACTED]@#g' \
        -e 's/([Aa]uthorization:[[:space:]]*[Bb]earer)[[:space:]]+[A-Za-z0-9._\/+=-]+/\1 [REDACTED]/g' \
        -e 's/([Aa]uthorization:[[:space:]]*[Bb]asic)[[:space:]]+[A-Za-z0-9._\/+=-]+/\1 [REDACTED]/g'
}
