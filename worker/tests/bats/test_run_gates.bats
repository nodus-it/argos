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

@test "unknown mode prints usage" {
    run bash "$SCRIPT" --nope
    [ "$status" -eq 2 ]
    [[ "$output" == *"usage:"* ]]
}
