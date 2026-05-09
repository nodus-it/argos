#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"

    # Stub `claude` on PATH so agent_claude_code_check passes and
    # agent_claude_code_run can be exercised end-to-end without the real CLI.
    BIN_DIR="$TEST_DIR/bin"
    mkdir -p "$BIN_DIR"
    cat > "$BIN_DIR/claude" <<'EOF'
#!/usr/bin/env bash
# Stub claude — records args + stdin, prints a fake stream-json result.
echo "$@" > "${CLAUDE_STUB_ARGS_FILE:-/dev/null}"
cat > "${CLAUDE_STUB_STDIN_FILE:-/dev/null}"
echo '{"type":"result","is_error":false,"result":"ok","session_id":"sess-1"}'
EOF
    chmod +x "$BIN_DIR/claude"
    PATH="$BIN_DIR:$PATH"
    export PATH

    export CLAUDE_STUB_ARGS_FILE="$TEST_DIR/claude.args"
    export CLAUDE_STUB_STDIN_FILE="$TEST_DIR/claude.stdin"

    # Concrete prompt files used by most tests.
    SYS_PROMPT="$TEST_DIR/system.md"
    USER_PROMPT="$TEST_DIR/user.md"
    echo "system prompt body" > "$SYS_PROMPT"
    echo "user prompt body"   > "$USER_PROMPT"

    # No env-leakage between tests.
    unset AGENT_NAME AGENT_TOKEN AGENT_CONFIG
    unset CLAUDE_CODE_OAUTH_TOKEN CLAUDE_CONFIG_DIR CLAUDE_MODEL

    # shellcheck source=../../worker/lib/claude.sh
    source worker/lib/claude.sh
    # shellcheck source=../../worker/lib/agents/claude_code.sh
    source worker/lib/agents/claude_code.sh
    # shellcheck source=../../worker/lib/agent.sh
    source worker/lib/agent.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# ─── Dispatcher (agent.sh) ──────────────────────────────────────────────────

@test "agent_run dispatches to claude_code by default" {
    run agent_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5
    [ "$status" -eq 0 ]
    [[ "$output" == *'"type":"result"'* ]]
}

@test "agent_run honours AGENT_NAME=claude_code explicitly" {
    AGENT_NAME=claude_code run agent_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5
    [ "$status" -eq 0 ]
}

@test "agent_run returns 30 for unknown AGENT_NAME" {
    AGENT_NAME=mystery_agent run agent_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5
    [ "$status" -eq 30 ]
    [[ "$output" == *"unknown AGENT_NAME"* ]]
}

@test "agent_check is OK when claude is on PATH" {
    run agent_check
    [ "$status" -eq 0 ]
}

@test "agent_check returns 30 for unknown AGENT_NAME" {
    AGENT_NAME=mystery_agent run agent_check
    [ "$status" -eq 30 ]
}

@test "agent_check_auth_error matches typical oauth/401 messages" {
    run agent_check_auth_error "Invalid API key"
    [ "$status" -eq 0 ]
    run agent_check_auth_error "request failed: 401 unauthorized"
    [ "$status" -eq 0 ]
    run agent_check_auth_error "token expired, please re-auth"
    [ "$status" -eq 0 ]
}

@test "agent_check_auth_error does not match unrelated messages" {
    run agent_check_auth_error "rate limit reached"
    [ "$status" -eq 1 ]
    run agent_check_auth_error ""
    [ "$status" -eq 1 ]
}

# ─── claude_code wrapper ────────────────────────────────────────────────────

@test "agent_claude_code_run requires --system-prompt-file" {
    run agent_claude_code_run \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5
    [ "$status" -ne 0 ]
    [[ "$output" == *"required"* ]]
}

@test "agent_claude_code_run requires --user-prompt-file" {
    run agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --max-turns 5
    [ "$status" -ne 0 ]
}

@test "agent_claude_code_run requires --max-turns" {
    run agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT"
    [ "$status" -ne 0 ]
}

@test "agent_claude_code_run rejects unknown args" {
    run agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --bogus value
    [ "$status" -ne 0 ]
    [[ "$output" == *"unknown arg"* ]]
}

@test "agent_claude_code_run defaults to stream-json + --verbose" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--output-format stream-json"* ]]
    [[ "$args" == *"--verbose"* ]]
}

@test "agent_claude_code_run --no-verbose drops --verbose" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --no-verbose > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" != *"--verbose"* ]]
}

@test "agent_claude_code_run with --output-format json drops --verbose" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --output-format json > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--output-format json"* ]]
    [[ "$args" != *"--verbose"* ]]
}

@test "agent_claude_code_run --include-partial passes --include-partial-messages" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --include-partial > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--include-partial-messages"* ]]
}

@test "agent_claude_code_run --resume passes --resume SESSION_ID" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --resume sess-abc > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--resume sess-abc"* ]]
}

@test "agent_claude_code_run always passes --permission-mode bypassPermissions" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--permission-mode bypassPermissions"* ]]
}

@test "agent_claude_code_run --json-schema reads file content into --json-schema" {
    schema="$TEST_DIR/schema.json"
    echo '{"type":"object","properties":{"a":{"type":"string"}}}' > "$schema"
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --output-format json \
        --json-schema "$schema" > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--json-schema"* ]]
    [[ "$args" == *'"a"'* ]]
}

@test "agent_claude_code_run feeds user-prompt-file to stdin" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    [ "$(cat "$CLAUDE_STUB_STDIN_FILE")" = "user prompt body" ]
}

@test "agent_claude_code_run inlines system prompt into --append-system-prompt" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--append-system-prompt system prompt body"* ]]
}

# ─── ENV mapping ────────────────────────────────────────────────────────────

@test "AGENT_TOKEN is mapped onto CLAUDE_CODE_OAUTH_TOKEN when not already set" {
    AGENT_TOKEN="oat-from-agent-token" agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    # The wrapper exports it; check via a sub-shell that re-reads from env.
    # We can't observe the inner sub-shell directly, so instead probe by
    # rerunning with the same setup and asserting we don't crash.
    [ "${CLAUDE_CODE_OAUTH_TOKEN:-}" = "oat-from-agent-token" ]
}

@test "Existing CLAUDE_CODE_OAUTH_TOKEN is not overwritten by AGENT_TOKEN" {
    export CLAUDE_CODE_OAUTH_TOKEN="oat-pre-existing"
    AGENT_TOKEN="oat-from-agent-token" agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    [ "$CLAUDE_CODE_OAUTH_TOKEN" = "oat-pre-existing" ]
}

@test "AGENT_CONFIG.config_dir maps onto CLAUDE_CONFIG_DIR" {
    AGENT_CONFIG='{"config_dir":"/tmp/argos-claude-state"}' \
        agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    [ "${CLAUDE_CONFIG_DIR:-}" = "/tmp/argos-claude-state" ]
}

@test "Phase-supplied --model wins over CLAUDE_MODEL env" {
    export CLAUDE_MODEL="claude-from-env"
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --model "claude-from-arg" > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--model claude-from-arg"* ]]
}

@test "CLAUDE_MODEL is used when no --model arg is given" {
    export CLAUDE_MODEL="claude-from-env"
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" == *"--model claude-from-env"* ]]
}

@test "No --model arg is emitted when neither env nor flag is set" {
    agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CLAUDE_STUB_ARGS_FILE")"
    [[ "$args" != *"--model"* ]]
}

@test "REPO_TOKEN is unset inside the claude sub-shell" {
    # The stub records its env-snapshot; we verify that REPO_TOKEN is
    # not visible to the claude process even though it's set in the parent.
    cat > "$BIN_DIR/claude" <<'EOF'
#!/usr/bin/env bash
echo "REPO_TOKEN=${REPO_TOKEN:-UNSET}" > "${CLAUDE_STUB_ENV_FILE:-/dev/null}"
echo '{"type":"result","is_error":false,"result":"ok"}'
EOF
    chmod +x "$BIN_DIR/claude"
    export CLAUDE_STUB_ENV_FILE="$TEST_DIR/claude.env"

    REPO_TOKEN="ghp_secret" agent_claude_code_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null

    [ "$(cat "$CLAUDE_STUB_ENV_FILE")" = "REPO_TOKEN=UNSET" ]
}
