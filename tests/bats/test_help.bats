#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    # shellcheck source=../../lib/help.sh
    source lib/help.sh
}

@test "help_main listet alle Top-Level-Commands" {
    out="$(help_main)"
    [[ "$out" == *"agent"* ]]
    [[ "$out" == *"init"* ]]
    [[ "$out" == *"task new"* ]]
    [[ "$out" == *"concept"* ]]
    [[ "$out" == *"implement"* ]]
    [[ "$out" == *"diff"* ]]
    [[ "$out" == *"push"* ]]
    [[ "$out" == *"shell"* ]]
    [[ "$out" == *"abort"* ]]
    [[ "$out" == *"prune"* ]]
}

@test "help_show ohne Argument == help_main" {
    a="$(help_show)"
    b="$(help_main)"
    [ "$a" = "$b" ]
}

@test "help_show concept enthaelt --fresh" {
    out="$(help_show concept)"
    [[ "$out" == *"--fresh"* ]]
}

@test "help_show implement erwaehnt --max-turns" {
    out="$(help_show implement)"
    [[ "$out" == *"--max-turns"* ]]
}

@test "help_show unbekannt gibt Fallback + main" {
    run --separate-stderr help_show xyz
    [ "$status" -eq 0 ]
    [[ "$stderr" == *"Kein Help-Eintrag"* ]]
    [[ "$output" == *"agent"* ]]
}

@test "help_show fuer alle Commands gibt non-empty stdout" {
    local cmd
    for cmd in init task concept implement diff push show-concept edit-concept show-notes edit-notes logs shell status abort prune; do
        out="$(help_show "$cmd")"
        [ -n "$out" ]
    done
}
