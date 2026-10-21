#!/usr/bin/env bats
# Task setup (.tools/docker/worker/workspace/task-setup.sh): run by the daemon
# in a fresh worktree. Test the dispatch, not composer/artisan themselves.

bats_require_minimum_version 1.5.0

setup() {
    SCRIPT="$PWD/.tools/docker/worker/workspace/task-setup.sh"
    TASK_DIR="$(mktemp -d)"
    cd "$TASK_DIR"
}

teardown() {
    cd /
    rm -rf "$TASK_DIR"
}

@test "runs the project's .argos/setup.sh from the worktree root when present" {
    mkdir -p .argos
    cat > .argos/setup.sh <<'SH'
echo "custom setup in $PWD with $DB_DATABASE"
touch custom-ran
SH
    DB_DATABASE=task_fix_login run bash "$SCRIPT"
    [ "$status" -eq 0 ]
    [[ "$output" == *"[setup] running .argos/setup.sh"* ]]
    [[ "$output" == *"custom setup in $TASK_DIR with task_fix_login"* ]]
    [ -f custom-ran ]
    # The custom script replaces the defaults.
    [[ "$output" != *"[setup] done"* ]]
}

@test "reports the exit code of a failing .argos/setup.sh" {
    mkdir -p .argos
    printf 'echo broken >&2\nexit 3\n' > .argos/setup.sh
    run bash "$SCRIPT"
    [ "$status" -eq 3 ]
    [[ "$output" == *"broken"* ]]
}

@test "default path without composer.json or artisan just logs done" {
    run bash "$SCRIPT"
    [ "$status" -eq 0 ]
    [ "$output" = "[setup] done" ]
}

@test "default path skips composer when it is not installed" {
    printf '{}\n' > composer.json
    # A PATH with only what the script needs, so no composer is found.
    mkdir -p "$TASK_DIR/.bin"
    ln -s "$(command -v bash)" "$TASK_DIR/.bin/bash"
    ln -s "$(command -v grep)" "$TASK_DIR/.bin/grep"
    PATH="$TASK_DIR/.bin" run bash "$SCRIPT"
    [ "$status" -eq 0 ]
    [[ "$output" == *"[setup] composer not available"* ]]
    [[ "$output" == *"[setup] done"* ]]
}
