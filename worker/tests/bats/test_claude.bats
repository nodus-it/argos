#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    RUNTIME_DIR="$TEST_DIR/runtime"
    export RUNTIME_DIR

    # shellcheck source=../../worker/lib/claude.sh
    source worker/lib/claude.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# ─── _claude_is_usage_limit_msg ─────────────────────────────────────────────

@test "is_usage_limit_msg matches classic API rate-limit wording" {
    run _claude_is_usage_limit_msg "rate limit exceeded"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "API Error: 429 too many requests"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "Overloaded error"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "credit balance is too low"
    [ "$status" -eq 0 ]
}

@test "is_usage_limit_msg matches Claude Max plan throttling" {
    # The real-world message from the May 2026 prod incident.
    run _claude_is_usage_limit_msg "You've hit your limit · resets 9:10pm (UTC)"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "You have reached your 5-hour limit"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "Weekly limit reached — try again Monday"
    [ "$status" -eq 0 ]
    run _claude_is_usage_limit_msg "Message limit reached"
    [ "$status" -eq 0 ]
}

@test "is_usage_limit_msg does not match unrelated errors" {
    run _claude_is_usage_limit_msg "Invalid API key"
    [ "$status" -eq 1 ]
    run _claude_is_usage_limit_msg "401 unauthorized"
    [ "$status" -eq 1 ]
    run _claude_is_usage_limit_msg "command not found: jq"
    [ "$status" -eq 1 ]
    run _claude_is_usage_limit_msg ""
    [ "$status" -eq 1 ]
}

# ─── _claude_extract_reset_at ───────────────────────────────────────────────

@test "extract_reset_at returns ISO8601 input verbatim" {
    run _claude_extract_reset_at "limit reached, resets 2026-05-13T21:10:00Z"
    [ "$status" -eq 0 ]
    [ "$output" = "2026-05-13T21:10:00Z" ]
}

@test "extract_reset_at parses 12h pm clock" {
    run _claude_extract_reset_at "You've hit your limit · resets 9:10pm (UTC)"
    [ "$status" -eq 0 ]
    [[ "$output" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T21:10:00Z$ ]]
}

@test "extract_reset_at parses 12h am clock without minutes" {
    run _claude_extract_reset_at "resets at 9am (UTC)"
    [ "$status" -eq 0 ]
    [[ "$output" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T09:00:00Z$ ]]
}

@test "extract_reset_at parses 24h clock" {
    run _claude_extract_reset_at "resets 21:10 (UTC)"
    [ "$status" -eq 0 ]
    [[ "$output" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T21:10:00Z$ ]]
}

@test "extract_reset_at picks tomorrow when the time already passed today" {
    # 00:01 UTC has almost certainly passed by the time the test runs;
    # we verify the date rolls forward by comparing against today.
    local today tomorrow
    today="$(date -u +%Y-%m-%d)"
    tomorrow="$(date -u -d "$today + 1 day" +%Y-%m-%d)"
    run _claude_extract_reset_at "resets 12:01am (UTC)"
    [ "$status" -eq 0 ]
    [[ "$output" == "${tomorrow}T00:01:00Z" || "$output" == "${today}T00:01:00Z" ]]
}

@test "extract_reset_at returns empty for messages with no reset hint" {
    run _claude_extract_reset_at "credit balance too low — recharge"
    [ "$status" -ne 0 ] || [ -z "$output" ]
}

# ─── _claude_write_usage_limit_env ──────────────────────────────────────────

@test "write_usage_limit_env writes USAGE_LIMIT_RESET_AT for Max-plan message" {
    _claude_write_usage_limit_env "You've hit your limit · resets 9:10pm (UTC)"
    [ -f "$RUNTIME_DIR/usage_limit.env" ]
    grep -qE '^USAGE_LIMIT_RESET_AT=[0-9]{4}-[0-9]{2}-[0-9]{2}T21:10:00Z$' \
        "$RUNTIME_DIR/usage_limit.env"
}

@test "write_usage_limit_env writes empty file when no reset hint present" {
    _claude_write_usage_limit_env "rate limit hit, please slow down"
    [ -f "$RUNTIME_DIR/usage_limit.env" ]
    ! grep -q "USAGE_LIMIT_RESET_AT" "$RUNTIME_DIR/usage_limit.env"
}

# ─── claude_check_usage_limit (top-level) ───────────────────────────────────

@test "check_usage_limit detects the Max-plan message via err_msg arg" {
    run claude_check_usage_limit "" "You've hit your limit · resets 9:10pm (UTC)"
    [ "$status" -eq 0 ]
    [ -f "$RUNTIME_DIR/usage_limit.env" ]
    grep -q "USAGE_LIMIT_RESET_AT=" "$RUNTIME_DIR/usage_limit.env"
}

@test "check_usage_limit detects rate_limit_error in stream log" {
    local log="$TEST_DIR/stream.log"
    printf '{"type":"system"}\n{"type":"error","error":{"type":"rate_limit_error","message":"please retry"}}\n' \
        > "$log"
    run claude_check_usage_limit "$log" ""
    [ "$status" -eq 0 ]
}

@test "check_usage_limit returns 1 on unrelated errors" {
    run claude_check_usage_limit "" "Invalid API key"
    [ "$status" -eq 1 ]
    [ ! -f "$RUNTIME_DIR/usage_limit.env" ]
}
