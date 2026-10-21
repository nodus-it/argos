#!/usr/bin/env bats
# Workspace gate runner (.tools/docker/worker/workspace/run-gates.sh): it only
# wires worker/lib/quality.sh to the daemon, so test the wiring, not the gates.

bats_require_minimum_version 1.5.0

setup() {
    SCRIPT=".tools/docker/worker/workspace/run-gates.sh"
    export ARGOS_AGENT_LIB_DIR="$PWD/worker/lib"
    rm -rf /workspace
    mkdir -p /workspace
    git -C /workspace init -q
}

teardown() {
    rm -rf /workspace
}

@test "--run on a non-PHP workspace skips every gate and prints JSON" {
    run --separate-stderr bash "$SCRIPT" --run r1
    [ "$status" -eq 0 ]
    [ "$(printf '%s' "$output" | jq -r '[.[]] | unique | join(",")')" = "skip" ]
    [ "$(printf '%s' "$output" | jq -r 'keys | length')" -ge 7 ]
}

@test "--run keeps the gate logs out of git" {
    run --separate-stderr bash "$SCRIPT" --run r1
    [ "$status" -eq 0 ]
    [ -d /workspace/.agent/logs ]
    grep -qxF '.agent/' /workspace/.git/info/exclude
    [ -z "$(git -C /workspace status --porcelain)" ]
}

@test "--run rejects run ids that are not file-name safe" {
    run bash "$SCRIPT" --run '../etc'
    [ "$status" -eq 2 ]
}

@test "--fix-prompt uses the worker's prompt with the gate log" {
    mkdir -p /workspace/.agent/logs
    printf 'FAILED  Tests\\LoginTest > redirects\n' > /workspace/.agent/logs/pest.r3.log
    run --separate-stderr bash "$SCRIPT" --fix-prompt pest r3
    [ "$status" -eq 0 ]
    [[ "$output" == *"# Quality-Gate-Fix: pest"* ]]
    [[ "$output" == *"LoginTest > redirects"* ]]
}

@test "--fix-prompt rejects unknown gates" {
    run bash "$SCRIPT" --fix-prompt rm r3
    [ "$status" -eq 2 ]
}

@test "--log-path maps the artisan gate to its log file" {
    run bash "$SCRIPT" --log-path artisan r4
    [ "$status" -eq 0 ]
    [ "$output" = "/workspace/.agent/logs/artisan-smoke.r4.log" ]
}

@test "--baseline succeeds without PHP tooling" {
    run bash "$SCRIPT" --baseline
    [ "$status" -eq 0 ]
}

# ─── --dir (task worktrees) ─────────────────────────────────────────────────

# make_worktree: main clone in /workspace/repo plus a task worktree.
make_worktree() {
    rm -rf /workspace
    mkdir -p /workspace/repo /workspace/tasks
    git -C /workspace/repo init -q -b main
    printf 'hello\n' > /workspace/repo/README.md
    git -C /workspace/repo add README.md
    git -C /workspace/repo -c user.name=t -c user.email=t@t commit -q -m init
    git -C /workspace/repo worktree add -q -b argos/fix-login /workspace/tasks/fix-login
}

@test "--dir runs the gates in a worktree under /workspace" {
    make_worktree
    run --separate-stderr bash "$SCRIPT" --dir /workspace/tasks/fix-login --run r1
    [ "$status" -eq 0 ]
    [ "$(printf '%s' "$output" | jq -r '[.[]] | unique | join(",")')" = "skip" ]
    [ -d /workspace/tasks/fix-login/.agent/logs ]
    [ -f /workspace/tasks/fix-login/.agent/quality-gates.r1.json ]
    [ ! -e /workspace/.agent ]
    [ ! -e /workspace/repo/.agent ]
}

@test "--dir keeps a worktree's logs out of git via the common dir's exclude" {
    make_worktree
    run --separate-stderr bash "$SCRIPT" --dir /workspace/tasks/fix-login --run r1
    [ "$status" -eq 0 ]
    grep -qxF '.agent/' /workspace/repo/.git/info/exclude
    [ -z "$(git -C /workspace/tasks/fix-login status --porcelain)" ]
    # Run again: the pattern is added only once.
    run --separate-stderr bash "$SCRIPT" --dir /workspace/repo --run r2
    [ "$status" -eq 0 ]
    [ "$(grep -cxF '.agent/' /workspace/repo/.git/info/exclude)" -eq 1 ]
}

@test "--dir moves the log paths into the checkout" {
    make_worktree
    run bash "$SCRIPT" --dir /workspace/tasks/fix-login --log-path artisan r4
    [ "$status" -eq 0 ]
    [ "$output" = "/workspace/tasks/fix-login/.agent/logs/artisan-smoke.r4.log" ]
}

@test "--dir /workspace is the default checkout" {
    run --separate-stderr bash "$SCRIPT" --dir /workspace --log-path pest r5
    [ "$status" -eq 0 ]
    [ "$output" = "/workspace/.agent/logs/pest.r5.log" ]
}

@test "--dir rejects paths outside /workspace, traversal and missing dirs" {
    mkdir -p /workspacefoo /workspace/tasks
    local dir
    for dir in /tmp /workspacefoo /workspace/../tmp /workspace/tasks/../../tmp /workspace/missing ''; do
        run bash "$SCRIPT" --dir "$dir" --run r1
        [ "$status" -eq 2 ]
        [[ "$output" == *"invalid checkout dir"* ]]
    done
    rm -rf /workspacefoo
}

@test "unknown mode prints usage" {
    run bash "$SCRIPT" --nope
    [ "$status" -eq 2 ]
    [[ "$output" == *"usage:"* ]]
}
