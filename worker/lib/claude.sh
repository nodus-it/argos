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
#
# Covers three families:
#  1. Anthropic API errors: "rate limit", "usage limit", "quota", "credit balance",
#     "overload", "too many requests", "529".
#  2. Claude Max plan throttling: "hit your limit", "reached your limit",
#     "5-hour limit", "weekly limit", "message limit".
#  3. Combined forms that mention both a reset hint and "limit" in proximity
#     (e.g. "You've hit your limit · resets 9:10pm (UTC)").
_claude_is_usage_limit_msg() {
    echo "$1" | grep -qiE \
        "rate.?limit|usage.?limit|message.?limit|weekly.?limit|[0-9]+.?(hour|h).?limit|hit (your|the) .*limit|reached (your|the) .*limit|quota|credit.?balance|overload|too.?many.?request|529"
}

# _claude_extract_reset_at: Parse a reset timestamp out of a Max-plan limit message.
# Echoes an ISO-8601 UTC timestamp on success, nothing on failure.
#
# Supports:
#  - Native ISO: "2026-05-13T21:10:00Z"
#  - 12h clock:  "resets 9:10pm (UTC)" / "resets at 9pm (UTC)"
#  - 24h clock:  "resets 21:10 (UTC)" / "resets at 21:10 UTC"
#
# For clock-only forms the date is inferred: today (UTC) if the time is still
# in the future, otherwise tomorrow.
_claude_extract_reset_at() {
    local msg="$1"
    local iso
    iso="$(echo "$msg" \
        | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[Z+][^, ]*' \
        | head -1 || true)"
    if [[ -n "$iso" ]]; then
        echo "$iso"
        return 0
    fi

    # Strip out a clock fragment like "9:10pm", "9pm", "21:10".
    local clock
    clock="$(echo "$msg" \
        | grep -oiE 'reset(s)?( at)? [0-9]{1,2}(:[0-9]{2})? ?(am|pm)?' \
        | head -1 \
        | sed -E 's/^reset(s)?( at)? *//I' \
        | tr -d ' ' \
        || true)"
    [[ -z "$clock" ]] && return 1

    local hour=0 min=0 ampm=""
    if [[ "$clock" =~ ^([0-9]{1,2})(:([0-9]{2}))?(am|pm|AM|PM)?$ ]]; then
        hour="${BASH_REMATCH[1]}"
        min="${BASH_REMATCH[3]:-0}"
        ampm="${BASH_REMATCH[4]:-}"
    else
        return 1
    fi
    ampm="$(echo "$ampm" | tr '[:upper:]' '[:lower:]')"

    # 12h → 24h conversion.
    if [[ "$ampm" == "pm" && "$hour" -lt 12 ]]; then
        hour=$(( hour + 12 ))
    elif [[ "$ampm" == "am" && "$hour" -eq 12 ]]; then
        hour=0
    fi

    # Compose the candidate timestamp on today's UTC date.
    local today now_epoch reset_epoch
    today="$(date -u +%Y-%m-%d)"
    local candidate
    candidate="$(printf '%sT%02d:%02d:00Z' "$today" "$hour" "$min")"
    now_epoch="$(date -u +%s)"
    reset_epoch="$(date -u -d "$candidate" +%s 2>/dev/null || echo 0)"

    # If the parsed time already passed today, the user means tomorrow.
    if (( reset_epoch <= now_epoch )); then
        local tomorrow
        tomorrow="$(date -u -d "$today + 1 day" +%Y-%m-%d 2>/dev/null || echo "$today")"
        candidate="$(printf '%sT%02d:%02d:00Z' "$tomorrow" "$hour" "$min")"
    fi

    echo "$candidate"
}

# _claude_write_usage_limit_env: Persist the limit signal (+ optional reset time) to disk.
# The PhaseRunner reads this file after the container exits.
_claude_write_usage_limit_env() {
    local msg="$1"
    local reset_at
    reset_at="$(_claude_extract_reset_at "$msg" || true)"
    mkdir -p "${RUNTIME_DIR}"
    if [[ -n "$reset_at" ]]; then
        printf 'USAGE_LIMIT_RESET_AT=%s\n' "$reset_at" > "${RUNTIME_DIR}/usage_limit.env"
    else
        printf '' > "${RUNTIME_DIR}/usage_limit.env"
    fi
}
