#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

@test "EXIT_-Konstanten haben die in WORKER-CONCEPT.md festgelegten Werte" {
    # shellcheck source=../../lib/error.sh
    source lib/error.sh
    [ "$EXIT_OK" -eq 0 ]
    [ "$EXIT_GENERAL" -eq 1 ]
    [ "$EXIT_PRECONDITION" -eq 2 ]
    [ "$EXIT_AUTH" -eq 3 ]
    [ "$EXIT_QUALITY_GATE" -eq 4 ]
    [ "$EXIT_NO_CHANGES" -eq 5 ]
    [ "$EXIT_LOCK" -eq 6 ]
}

@test "die exited mit gegebenem Code und schreibt Message auf stderr" {
    run --separate-stderr bash -c 'source lib/error.sh; die 2 "boom"'
    [ "$status" -eq 2 ]
    [ -z "$output" ]
    [[ "$stderr" == *"Error: boom"* ]]
}

@test "die ohne Message gibt nichts auf stderr aus, exited aber" {
    run --separate-stderr bash -c 'source lib/error.sh; die 4'
    [ "$status" -eq 4 ]
    [ -z "$output" ]
    [ -z "$stderr" ]
}
