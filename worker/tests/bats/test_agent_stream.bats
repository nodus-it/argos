#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    # shellcheck source=../../worker/lib/agent_stream.sh
    source worker/lib/agent_stream.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "agent_stream_tee forwards stdin to stdout unchanged" {
    out="$(printf '%s\n' '{"type":"assistant"}' '{"type":"result"}' \
        | agent_stream_tee "$TEST_DIR/stream.log" 2>/dev/null)"
    [[ "$out" == '{"type":"assistant"}'$'\n''{"type":"result"}' ]]
}

@test "agent_stream_tee persists the full stream to the log file" {
    printf '%s\n' '{"type":"assistant"}' '{"type":"result"}' \
        | agent_stream_tee "$TEST_DIR/stream.log" >/dev/null 2>&1
    run cat "$TEST_DIR/stream.log"
    [[ "$output" == *'"type":"assistant"'* ]]
    [[ "$output" == *'"type":"result"'* ]]
}

@test "agent_stream_tee mirrors the full stream to stderr" {
    err="$(printf '%s\n' '{"type":"thinking"}' '{"type":"result"}' \
        | agent_stream_tee "$TEST_DIR/stream.log" 2>&1 >/dev/null)"
    [[ "$err" == *'"type":"thinking"'* ]]
    [[ "$err" == *'"type":"result"'* ]]
}

@test "agent_stream_tee fails loudly without a log path" {
    run agent_stream_tee </dev/null
    [[ "$status" -ne 0 ]]
}
