#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    # shellcheck source=../../lib/result.sh
    source lib/result.sh
}

@test "result_emit baut JSON mit allen Pflichtfeldern" {
    local out
    out="$(result_emit \
        phase concept \
        task_id task-001 \
        --int iteration 1 \
        status completed \
        started_at 2026-04-30T10:00:00Z \
        finished_at 2026-04-30T10:00:30Z \
        --int duration_ms 30000 \
        --int exit_code 0)"
    [ -n "$out" ]
    [ "$(jq -r .phase <<< "$out")" = "concept" ]
    [ "$(jq -r .task_id <<< "$out")" = "task-001" ]
    [ "$(jq -r .iteration <<< "$out")" = "1" ]
    [ "$(jq -r .status <<< "$out")" = "completed" ]
    [ "$(jq -r .exit_code <<< "$out")" = "0" ]
    [ "$(jq -r '.iteration | type' <<< "$out")" = "number" ]
    [ "$(jq -r '.exit_code | type' <<< "$out")" = "number" ]
    [ "$(jq -r '.status | type' <<< "$out")" = "string" ]
}

@test "result_emit fehlschlaegt wenn Pflichtfeld fehlt" {
    run --separate-stderr result_emit \
        phase concept \
        task_id task-001 \
        status completed
    [ "$status" -eq 1 ]
    [[ "$stderr" == *"required field missing"* ]]
}

@test "result_emit akzeptiert --raw fuer Arrays" {
    local out
    out="$(result_emit \
        phase implement \
        task_id t \
        --int iteration 1 \
        status completed \
        started_at x \
        finished_at y \
        --int exit_code 0 \
        --raw changed_files '["a.php","b.php"]')"
    [ "$(jq -r '.changed_files[0]' <<< "$out")" = "a.php" ]
    [ "$(jq -r '.changed_files | length' <<< "$out")" = "2" ]
}

@test "result_emit gibt einzeilige JSON aus (-c Mode)" {
    local out
    out="$(result_emit \
        phase concept \
        task_id t \
        --int iteration 1 \
        status completed \
        started_at x \
        finished_at y \
        --int exit_code 0)"
    [ "$(echo "$out" | wc -l)" -eq 1 ]
}
