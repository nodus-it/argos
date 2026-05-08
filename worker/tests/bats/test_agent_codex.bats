#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"

    # Stub codex on PATH so the wrapper has something to call.
    BIN_DIR="$TEST_DIR/bin"
    mkdir -p "$BIN_DIR"
    cat > "$BIN_DIR/codex" <<'EOF'
#!/usr/bin/env bash
# Stub codex CLI — records args + stdin, prints fake newline-delimited
# JSON events to stdout.
echo "$@" > "${CODEX_STUB_ARGS_FILE:-/dev/null}"
cat > "${CODEX_STUB_STDIN_FILE:-/dev/null}"
echo '{"type":"message","message":"hello from codex"}'
echo '{"type":"final","session_id":"sess-codex-1","usage":{"input_tokens":10,"output_tokens":20}}'
EOF
    chmod +x "$BIN_DIR/codex"
    PATH="$BIN_DIR:$PATH"
    export PATH

    export CODEX_STUB_ARGS_FILE="$TEST_DIR/codex.args"
    export CODEX_STUB_STDIN_FILE="$TEST_DIR/codex.stdin"

    SYS_PROMPT="$TEST_DIR/system.md"
    USER_PROMPT="$TEST_DIR/user.md"
    echo "you are an agent" > "$SYS_PROMPT"
    echo "implement the feature" > "$USER_PROMPT"

    unset AGENT_NAME AGENT_TOKEN AGENT_CONFIG OPENAI_API_KEY

    # shellcheck source=../../worker/lib/claude.sh
    source worker/lib/claude.sh
    # shellcheck source=../../worker/lib/agents/claude_code.sh
    source worker/lib/agents/claude_code.sh
    # shellcheck source=../../worker/lib/agents/codex.sh
    source worker/lib/agents/codex.sh
    # shellcheck source=../../worker/lib/agent.sh
    source worker/lib/agent.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# ─── Dispatcher ─────────────────────────────────────────────────────────────

@test "agent_run dispatches to codex when AGENT_NAME=codex" {
    AGENT_NAME=codex run agent_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5
    [ "$status" -eq 0 ]
    [[ "$output" == *'"type":"result"'* ]]
}

@test "agent_check passes for codex when codex is on PATH" {
    AGENT_NAME=codex run agent_check
    [ "$status" -eq 0 ]
}

# ─── Wrapper invocation ─────────────────────────────────────────────────────

@test "agent_codex_run requires --system-prompt-file" {
    run agent_codex_run --user-prompt-file "$USER_PROMPT" --max-turns 5
    [ "$status" -ne 0 ]
    [[ "$output" == *"required"* ]]
}

@test "agent_codex_run requires --user-prompt-file" {
    run agent_codex_run --system-prompt-file "$SYS_PROMPT" --max-turns 5
    [ "$status" -ne 0 ]
}

@test "agent_codex_run rejects unknown args" {
    run agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --bogus value
    [ "$status" -ne 0 ]
    [[ "$output" == *"unknown arg"* ]]
}

@test "agent_codex_run invokes codex with exec --json -" {
    agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    args="$(cat "$CODEX_STUB_ARGS_FILE")"
    [[ "$args" == *"exec"* ]]
    [[ "$args" == *"--json"* ]]
}

@test "agent_codex_run prepends system prompt to user prompt on stdin" {
    agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    stdin="$(cat "$CODEX_STUB_STDIN_FILE")"
    [[ "$stdin" == *"you are an agent"* ]]
    [[ "$stdin" == *"implement the feature"* ]]
    [[ "$stdin" == *"---"* ]]
}

@test "agent_codex_run forwards --model when given" {
    agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --model gpt-5-codex > /dev/null
    args="$(cat "$CODEX_STUB_ARGS_FILE")"
    [[ "$args" == *"--model gpt-5-codex"* ]]
}

@test "agent_codex_run forwards --resume session id" {
    agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --resume sess-x > /dev/null
    args="$(cat "$CODEX_STUB_ARGS_FILE")"
    [[ "$args" == *"--resume sess-x"* ]]
}

@test "agent_codex_run forwards --output-schema when --json-schema set" {
    schema="$TEST_DIR/schema.json"
    echo '{"type":"object"}' > "$schema"
    agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 \
        --json-schema "$schema" > /dev/null
    args="$(cat "$CODEX_STUB_ARGS_FILE")"
    [[ "$args" == *"--output-schema"* ]]
}

@test "agent_codex_run synthesises trailing result-event with is_error=false on success" {
    out="$(agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5)"
    last="$(echo "$out" | tail -1)"
    is_error="$(echo "$last" | jq -r '.is_error')"
    type="$(echo "$last" | jq -r '.type')"
    [ "$type" = "result" ]
    [ "$is_error" = "false" ]
}

@test "agent_codex_run emits is_error=true and non-zero exit on codex failure" {
    cat > "$BIN_DIR/codex" <<'EOF'
#!/usr/bin/env bash
echo "boom" >&2
exit 5
EOF
    chmod +x "$BIN_DIR/codex"

    set +e
    out="$(agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5)"
    rc=$?
    set -e

    [ "$rc" -ne 0 ]
    last="$(echo "$out" | tail -1)"
    is_error="$(echo "$last" | jq -r '.is_error')"
    [ "$is_error" = "true" ]
}

# ─── ENV mapping ────────────────────────────────────────────────────────────

@test "AGENT_TOKEN maps onto OPENAI_API_KEY when not already set" {
    AGENT_TOKEN="sk-from-agent" agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    [ "${OPENAI_API_KEY:-}" = "sk-from-agent" ]
}

@test "Existing OPENAI_API_KEY is not overwritten by AGENT_TOKEN" {
    export OPENAI_API_KEY="sk-pre-existing"
    AGENT_TOKEN="sk-from-agent" agent_codex_run \
        --system-prompt-file "$SYS_PROMPT" \
        --user-prompt-file "$USER_PROMPT" \
        --max-turns 5 > /dev/null
    [ "$OPENAI_API_KEY" = "sk-pre-existing" ]
}

# ─── Detection helpers ──────────────────────────────────────────────────────

@test "agent_codex_check_usage_limit matches typical 429 / quota messages" {
    AGENT_NAME=codex run agent_check_usage_limit "" "rate limit reached"
    [ "$status" -eq 0 ]
    AGENT_NAME=codex run agent_check_usage_limit "" "HTTP 429 too many requests"
    [ "$status" -eq 0 ]
    AGENT_NAME=codex run agent_check_usage_limit "" "quota exceeded for org"
    [ "$status" -eq 0 ]
}

@test "agent_codex_check_auth_error matches oauth / 401 messages" {
    AGENT_NAME=codex run agent_check_auth_error "401 unauthorized"
    [ "$status" -eq 0 ]
    AGENT_NAME=codex run agent_check_auth_error "Please sign in with ChatGPT"
    [ "$status" -eq 0 ]
    AGENT_NAME=codex run agent_check_auth_error "rate limit"
    [ "$status" -eq 1 ]
}
