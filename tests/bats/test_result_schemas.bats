#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

# Validiert, dass von result_emit produzierte JSONs gegen die
# schemas/result.<phase>.schema.json validieren — Drift-Schutz zwischen
# lib/result.sh und Schema-Files.

setup() {
    # shellcheck source=../../lib/result.sh
    source lib/result.sh
    TEST_DIR="$(mktemp -d)"
}

teardown() {
    rm -rf "$TEST_DIR"
}

_validate() {
    local schema="$1"
    local data="$2"
    check-jsonschema --schemafile "$schema" "$data"
}

@test "result.concept passt zum Schema" {
    local out="$TEST_DIR/concept.json"
    result_emit \
        phase concept \
        task_id task-001 \
        --int iteration 1 \
        status completed \
        started_at 2026-04-30T10:00:00Z \
        finished_at 2026-04-30T10:00:30Z \
        --int duration_ms 30000 \
        --int exit_code 0 \
        concept_path /workspace/.agent/concept.md \
        --int concept_history_count 0 \
        claude_session_id sess-x \
        --raw claude_total_cost_usd 0.001 \
        > "$out"
    _validate schemas/result.concept.schema.json "$out"
}

@test "result.implement passt zum Schema" {
    local out="$TEST_DIR/implement.json"
    result_emit \
        phase implement \
        task_id task-001 \
        --int iteration 2 \
        status completed \
        started_at 2026-04-30T11:00:00Z \
        finished_at 2026-04-30T11:05:00Z \
        --int duration_ms 300000 \
        --int exit_code 0 \
        --raw changed_files '["a.php","b.php"]' \
        --raw quality_gates '{"pint":"pass","pest":"pass","phpunit":"skip","phpstan":"skip"}' \
        claude_session_id sess-y \
        --raw claude_total_cost_usd 0.05 \
        > "$out"
    _validate schemas/result.implement.schema.json "$out"
}

@test "result.diff passt zum Schema" {
    local out="$TEST_DIR/diff.json"
    result_emit \
        phase diff \
        task_id task-001 \
        --int iteration 1 \
        status completed \
        started_at 2026-04-30T11:10:00Z \
        finished_at 2026-04-30T11:10:01Z \
        --int duration_ms 1000 \
        --int exit_code 0 \
        --int files_changed 2 \
        --int insertions 30 \
        --int deletions 5 \
        > "$out"
    _validate schemas/result.diff.schema.json "$out"
}

@test "result.push passt zum Schema" {
    local out="$TEST_DIR/push.json"
    result_emit \
        phase push \
        task_id task-001 \
        --int iteration 1 \
        status completed \
        started_at 2026-04-30T11:20:00Z \
        finished_at 2026-04-30T11:20:05Z \
        --int duration_ms 5000 \
        --int exit_code 0 \
        branch ai/task-001-1714506000 \
        commit_sha deadbeef \
        remote_url https://example.com/r.git \
        commit_subject "feat: x" \
        > "$out"
    _validate schemas/result.push.schema.json "$out"
}
