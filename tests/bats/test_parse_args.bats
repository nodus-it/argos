#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    # shellcheck source=../../lib/parse_args.sh
    source lib/parse_args.sh
}

@test "concept task-001 — einfacher Phase-Aufruf" {
    parse_args concept task-001
    [ "$ARG_COMMAND" = "concept" ]
    [ "$ARG_SUBCOMMAND" = "" ]
    [ "$ARG_TASK_ID" = "task-001" ]
    [ "${#ARG_REMAINING[@]}" -eq 0 ]
}

@test "concept task-001 --fresh — Boolean-Flag wird zu ARG_FLAG_FRESH=true" {
    parse_args concept task-001 --fresh
    [ "$ARG_COMMAND" = "concept" ]
    [ "$ARG_TASK_ID" = "task-001" ]
    [ "$ARG_FLAG_FRESH" = "true" ]
}

@test "implement task-001 --max-turns=80 — Wert via =" {
    parse_args implement task-001 --max-turns=80
    [ "$ARG_FLAG_MAX_TURNS" = "80" ]
}

@test "implement task-001 --max-turns 80 — Wert via Leerzeichen" {
    parse_args implement task-001 --max-turns 80
    [ "$ARG_FLAG_MAX_TURNS" = "80" ]
    [ "${#ARG_REMAINING[@]}" -eq 0 ]
}

@test "implement task-001 --continue --max-turns=50 — kombinierte Flags" {
    parse_args implement task-001 --continue --max-turns=50
    [ "$ARG_FLAG_CONTINUE" = "true" ]
    [ "$ARG_FLAG_MAX_TURNS" = "50" ]
}

@test "task new task-001 — Subcommand-Familie wird erkannt" {
    parse_args task new task-001
    [ "$ARG_COMMAND" = "task" ]
    [ "$ARG_SUBCOMMAND" = "new" ]
    [ "$ARG_TASK_ID" = "task-001" ]
}

@test "task list — Subcommand ohne Task-ID" {
    parse_args task list
    [ "$ARG_COMMAND" = "task" ]
    [ "$ARG_SUBCOMMAND" = "list" ]
    [ "$ARG_TASK_ID" = "" ]
}

@test "diff task-001 --stat — Boolean-Flag" {
    parse_args diff task-001 --stat
    [ "$ARG_FLAG_STAT" = "true" ]
}

@test "diff task-001 --file=src/X.php" {
    parse_args diff task-001 --file=src/X.php
    [ "$ARG_FLAG_FILE" = "src/X.php" ]
}

@test "logs task-001 --phase=concept --iteration=2" {
    parse_args logs task-001 --phase=concept --iteration=2
    [ "$ARG_FLAG_PHASE" = "concept" ]
    [ "$ARG_FLAG_ITERATION" = "2" ]
}

@test "leeres Argument-Array setzt alle Vars auf leer" {
    parse_args
    [ "$ARG_COMMAND" = "" ]
    [ "$ARG_SUBCOMMAND" = "" ]
    [ "$ARG_TASK_ID" = "" ]
    [ "${#ARG_REMAINING[@]}" -eq 0 ]
}

@test "wiederholter Aufruf bereinigt vorherige Flags" {
    parse_args concept task-001 --fresh
    [ "${ARG_FLAG_FRESH:-}" = "true" ]
    parse_args concept task-002
    [ "${ARG_FLAG_FRESH:-}" = "" ]
    [ "$ARG_TASK_ID" = "task-002" ]
}

@test "-- markiert Ende der Flags" {
    parse_args push task-001 -- --weird-positional
    [ "$ARG_COMMAND" = "push" ]
    [ "$ARG_TASK_ID" = "task-001" ]
    [ "${ARG_REMAINING[0]:-}" = "--weird-positional" ]
}
