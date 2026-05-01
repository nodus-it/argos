#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export STATE_FILE="$TEST_DIR/state.json"
    # shellcheck source=../../worker/lib/state.sh
    source worker/lib/state.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "state_init erzeugt valide Datei mit allen Pflichtfeldern" {
    state_init "task-001" "https://example.com/repo.git" "main"
    [ -f "$STATE_FILE" ]
    [ "$(jq -r .task_id "$STATE_FILE")" = "task-001" ]
    [ "$(jq -r .schema_version "$STATE_FILE")" = "1" ]
    [ "$(jq -r .repo.url "$STATE_FILE")" = "https://example.com/repo.git" ]
    [ "$(jq -r .repo.base_branch "$STATE_FILE")" = "main" ]
    [ "$(jq -r .repo.feature_branch "$STATE_FILE")" = "null" ]
    [ "$(jq -r '.phases | keys | join(",")' "$STATE_FILE")" = "concept,diff,implement,push" ]
    [ "$(jq -r '.phases.concept.current_status' "$STATE_FILE")" = "pending" ]
    [ "$(jq -r '.phases.implement.iterations | length' "$STATE_FILE")" = "0" ]
}

@test "state_validate akzeptiert frisch initialisierte Datei" {
    state_init "task-001" "url" "main"
    state_validate
}

@test "state_validate lehnt fehlendes File ab" {
    rm -f "$STATE_FILE"
    run state_validate
    [ "$status" -eq 1 ]
}

@test "state_validate lehnt falsche schema_version ab" {
    state_init "task-001" "url" "main"
    jq '.schema_version = 999' "$STATE_FILE" > "$STATE_FILE.tmp" && mv "$STATE_FILE.tmp" "$STATE_FILE"
    run state_validate
    [ "$status" -eq 1 ]
}

@test "state_add_iteration nummeriert beginnend mit 1, dann 2" {
    state_init "task-001" "url" "main"
    n="$(state_add_iteration concept '{"fresh":false}')"
    [ "$n" -eq 1 ]
    [ "$(jq -r '.phases.concept.iterations | length' "$STATE_FILE")" = "1" ]
    [ "$(jq -r '.phases.concept.current_status' "$STATE_FILE")" = "running" ]
    [ "$(jq -r '.phases.concept.iterations[0].n' "$STATE_FILE")" = "1" ]
    [ "$(jq -r '.phases.concept.iterations[0].status' "$STATE_FILE")" = "running" ]
    [ "$(jq -r '.phases.concept.iterations[0].flags.fresh' "$STATE_FILE")" = "false" ]

    n2="$(state_add_iteration concept '{"fresh":true}')"
    [ "$n2" -eq 2 ]
    [ "$(jq -r '.phases.concept.iterations[1].flags.fresh' "$STATE_FILE")" = "true" ]
}

@test "state_update_iteration setzt Endstatus, exit_code, error_message" {
    state_init "task-001" "url" "main"
    state_add_iteration concept '{}' >/dev/null
    state_update_iteration concept 1 completed 0
    [ "$(jq -r '.phases.concept.current_status' "$STATE_FILE")" = "completed" ]
    [ "$(jq -r '.phases.concept.iterations[0].status' "$STATE_FILE")" = "completed" ]
    [ "$(jq -r '.phases.concept.iterations[0].exit_code' "$STATE_FILE")" = "0" ]
    [ "$(jq -r '.phases.concept.iterations[0].error_message' "$STATE_FILE")" = "null" ]
    [ "$(jq -r '.phases.concept.iterations[0].finished_at' "$STATE_FILE")" != "null" ]
}

@test "state_update_iteration mit error_message und failed_gate setzt diese" {
    state_init "task-001" "url" "main"
    state_add_iteration implement '{}' >/dev/null
    state_update_iteration implement 1 quality_gate_failed 4 "pest schlägt fehl" pest
    [ "$(jq -r '.phases.implement.iterations[0].error_message' "$STATE_FILE")" = "pest schlägt fehl" ]
    [ "$(jq -r '.phases.implement.iterations[0].failed_gate' "$STATE_FILE")" = "pest" ]
}

@test "state_set_feature_branch / state_get_feature_branch" {
    state_init "task-001" "url" "main"
    [ -z "$(state_get_feature_branch)" ]
    state_set_feature_branch "ai/task-001-1714506000"
    [ "$(state_get_feature_branch)" = "ai/task-001-1714506000" ]
}

@test "state_write_atomic refused invalid JSON" {
    state_init "task-001" "url" "main"
    run --separate-stderr bash -c "STATE_FILE='$STATE_FILE'; source worker/lib/state.sh; echo 'NOT JSON' | state_write_atomic"
    [ "$status" -ne 0 ]
    [[ "$stderr" == *"invalid JSON"* ]]
    # state.json muss unverändert bleiben
    [ "$(jq -r .task_id "$STATE_FILE")" = "task-001" ]
}

@test "state_get_iteration_count zaehlt iterations richtig" {
    state_init "task-001" "url" "main"
    [ "$(state_get_iteration_count concept)" -eq 0 ]
    state_add_iteration concept '{}' >/dev/null
    state_add_iteration concept '{}' >/dev/null
    [ "$(state_get_iteration_count concept)" -eq 2 ]
    [ "$(state_get_iteration_count implement)" -eq 0 ]
}
