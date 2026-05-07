#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export AGENT_HOME="$TEST_DIR/.agent"
    mkdir -p "$AGENT_HOME/tasks"

    # Mock-Docker: protokolliert calls in $TEST_DIR/docker.log
    # und simuliert Volume-State in $TEST_DIR/volumes/.
    export FAKE_VOL_DIR="$TEST_DIR/volumes"
    mkdir -p "$FAKE_VOL_DIR"
    export DOCKER_LOG="$TEST_DIR/docker.log"
    : > "$DOCKER_LOG"

    docker() {
        echo "$*" >> "$DOCKER_LOG"
        case "$1 $2" in
            "volume create")
                touch "$FAKE_VOL_DIR/$3"
                echo "$3"
                ;;
            "volume rm")
                if [[ -f "$FAKE_VOL_DIR/$3" ]]; then
                    rm -f "$FAKE_VOL_DIR/$3"
                    return 0
                fi
                return 1
                ;;
            "volume inspect")
                [[ -f "$FAKE_VOL_DIR/$3" ]]
                ;;
            "volume ls")
                # erwartet --filter name=task_ws_ --format '{{.Name}}'
                local f
                for f in "$FAKE_VOL_DIR"/task_ws_*; do
                    [[ -f "$f" ]] || continue
                    basename "$f"
                done
                ;;
            *)
                return 1
                ;;
        esac
    }
    export -f docker

    # shellcheck source=../../worker/lib/tasks.sh
    source worker/lib/tasks.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "task_id_validate akzeptiert valide IDs" {
    task_id_validate "task-001"
    task_id_validate "demo-helloworld"
    task_id_validate "abc"
    task_id_validate "a"
    task_id_validate "1"
    task_id_validate "ab12-x9"
}

@test "task_id_validate lehnt invalide IDs ab" {
    run task_id_validate ""
    [ "$status" -eq 1 ]
    run task_id_validate "Task1"   # Großbuchstabe
    [ "$status" -eq 1 ]
    run task_id_validate "-task"   # Bindestrich am Anfang
    [ "$status" -eq 1 ]
    run task_id_validate "task-"   # Bindestrich am Ende
    [ "$status" -eq 1 ]
    run task_id_validate "ta sk"   # Whitespace
    [ "$status" -eq 1 ]
    run task_id_validate "$(printf 'a%.0s' {1..41})"  # 41 Zeichen
    [ "$status" -eq 1 ]
}

@test "task_volume_name liefert task_ws_<id>" {
    [ "$(task_volume_name task-001)" = "task_ws_task-001" ]
}

@test "task_create_volume + task_volume_exists + task_delete_volume" {
    run task_volume_exists task-001
    [ "$status" -ne 0 ]
    task_create_volume task-001
    run task_volume_exists task-001
    [ "$status" -eq 0 ]
    task_delete_volume task-001
    run task_volume_exists task-001
    [ "$status" -ne 0 ]
}

@test "task_list_volumes listet existierende Task-Volumes" {
    task_create_volume task-a
    task_create_volume task-b
    out="$(task_list_volumes)"
    [[ "$out" == *"task-a"* ]]
    [[ "$out" == *"task-b"* ]]
}

@test "task_list dedupliziert Host- und Docker-Side-Eintraege" {
    mkdir -p "$AGENT_HOME/tasks/task-a"
    mkdir -p "$AGENT_HOME/tasks/task-only-host"
    task_create_volume task-a
    task_create_volume task-only-volume
    out="$(task_list)"
    [[ "$out" == *"task-a"* ]]
    [[ "$out" == *"task-only-host"* ]]
    [[ "$out" == *"task-only-volume"* ]]
    # Dedup-check: task-a darf nur einmal vorkommen
    count="$(echo "$out" | grep -c '^task-a$')"
    [ "$count" -eq 1 ]
}

@test "task_orphans liefert Volumes ohne Host-Eintrag" {
    mkdir -p "$AGENT_HOME/tasks/task-a"
    task_create_volume task-a
    task_create_volume task-orphan
    out="$(task_orphans)"
    [[ "$out" == *"task-orphan"* ]]
    [[ "$out" != *"task-a"* ]]
}
