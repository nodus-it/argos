#!/usr/bin/env bash
# lib/claude.sh — helpers for detecting Claude API usage/rate-limit errors.
#
# shellcheck shell=bash

# claude_check_usage_limit: Detect usage/rate limit in stream log or error message.
# Writes ${RUNTIME_DIR}/usage_limit.env when detected (empty or with USAGE_LIMIT_RESET_AT=<iso>).
# Args: $1=log_file (stream log path, optional), $2=err_message (from is_error result, optional)
# Returns: 0 if usage limit detected, 1 otherwise
claude_check_usage_limit() {
    local log_file="${1:-}"
    local err_msg="${2:-}"

    # Fast path: check the provided error-message string
    if [[ -n "$err_msg" ]] && _claude_is_usage_limit_msg "$err_msg"; then
        _claude_write_usage_limit_env "$err_msg"
        return 0
    fi

    # Check log file for {"type":"error",...} events emitted by the CLI
    if [[ -n "$log_file" && -f "$log_file" ]]; then
        local error_line
        error_line="$(grep -m1 '"type":"error"' "$log_file" 2>/dev/null || true)"
        if [[ -n "$error_line" ]]; then
            local err_type err_text
            err_type="$(printf '%s' "$error_line" | jq -r '.error.type // ""' 2>/dev/null || true)"
            err_text="$(printf '%s' "$error_line" | jq -r '.error.message // ""' 2>/dev/null || true)"
            if [[ "$err_type" == "rate_limit_error" ]] || _claude_is_usage_limit_msg "$err_text"; then
                _claude_write_usage_limit_env "$err_text"
                return 0
            fi
        fi
    fi

    return 1
}

# _claude_is_usage_limit_msg: True if the message looks like a usage/rate-limit error.
_claude_is_usage_limit_msg() {
    echo "$1" | grep -qiE \
        "rate.?limit|usage.?limit|quota|credit.?balance|overload|too.?many.?request|529"
}

# _claude_write_usage_limit_env: Persist the limit signal (+ optional reset time) to disk.
# The PhaseRunner reads this file after the container exits.
_claude_write_usage_limit_env() {
    local msg="$1"
    local reset_at
    reset_at="$(echo "$msg" \
        | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[Z+][^, ]*' \
        | head -1 || true)"
    mkdir -p "${RUNTIME_DIR}"
    if [[ -n "$reset_at" ]]; then
        printf 'USAGE_LIMIT_RESET_AT=%s\n' "$reset_at" > "${RUNTIME_DIR}/usage_limit.env"
    else
        printf '' > "${RUNTIME_DIR}/usage_limit.env"
    fi
}
