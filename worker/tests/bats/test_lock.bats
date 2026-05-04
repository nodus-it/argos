#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export LOCK_FILE="$TEST_DIR/.lock"
    # shellcheck source=../../worker/lib/lock.sh
    source worker/lib/lock.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "lock_acquire setzt Lock-File mit korrekten Feldern" {
    lock_acquire concept
    [ -f "$LOCK_FILE" ]
    [ "$(jq -r .phase "$LOCK_FILE")" = "concept" ]
    [ "$(jq -r '.pid | type' "$LOCK_FILE")" = "number" ]
    [ -n "$(jq -r .started_at "$LOCK_FILE")" ]
}

@test "lock_acquire schlaegt mit Code 6 fehl wenn Lock besteht" {
    lock_acquire concept
    run --separate-stderr lock_acquire implement
    [ "$status" -eq 6 ]
    [[ "$stderr" == *"bereits gesetzt"* || "$stderr" == *"stale"* ]]
}

@test "lock_release entfernt den Lock und ist idempotent" {
    lock_acquire concept
    lock_release
    [ ! -f "$LOCK_FILE" ]
    lock_release   # darf nicht failen
}

@test "lock_acquire nach release funktioniert" {
    lock_acquire concept
    lock_release
    lock_acquire implement
    [ "$(jq -r .phase "$LOCK_FILE")" = "implement" ]
}

@test "lock_is_stale erkennt alten Lock" {
    LOCK_STALE_SECONDS=1 lock_acquire concept
    sleep 2
    LOCK_STALE_SECONDS=1 run lock_is_stale
    [ "$status" -eq 0 ]
}

@test "lock_is_stale ist false fuer frischen Lock" {
    lock_acquire concept
    run lock_is_stale
    [ "$status" -eq 1 ]
}

@test "lock_force_release entfernt auch bestehenden Lock" {
    lock_acquire concept
    lock_force_release
    [ ! -f "$LOCK_FILE" ]
}

@test "lock_info ohne Lock liefert nichts" {
    run lock_info
    [ "$status" -eq 0 ]
    [ -z "$output" ]
}

@test "lock_info mit Lock liefert tab-separierte Felder" {
    lock_acquire concept
    run lock_info
    [ "$status" -eq 0 ]
    [[ "$output" == *"concept"* ]]
}
