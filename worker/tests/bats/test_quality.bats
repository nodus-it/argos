#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    QUALITY_LOG_DIR="$TEST_DIR/logs"
    mkdir -p "$QUALITY_LOG_DIR"
    export QUALITY_LOG_DIR

    # shellcheck source=../../worker/lib/quality.sh
    source worker/lib/quality.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# ─── quality_gate_log_path ──────────────────────────────────────────────────

@test "quality_gate_log_path maps artisan slug to artisan-smoke base" {
    local p
    p="$(quality_gate_log_path artisan 2.fix1)"
    [ "$p" = "$QUALITY_LOG_DIR/artisan-smoke.2.fix1.log" ]
}

@test "quality_gate_log_path maps debug_code slug to debug-code base" {
    local p
    p="$(quality_gate_log_path debug_code 1)"
    [ "$p" = "$QUALITY_LOG_DIR/debug-code.1.log" ]
}

@test "quality_gate_log_path returns slug as-is for pest" {
    local p
    p="$(quality_gate_log_path pest 3.fix2)"
    [ "$p" = "$QUALITY_LOG_DIR/pest.3.fix2.log" ]
}

# ─── quality_gate_log_converged ─────────────────────────────────────────────

@test "log_converged returns 0 when fix-1 is identical to the initial run" {
    printf 'FAIL: pest test foo\n--- summary ---\n' > "$QUALITY_LOG_DIR/pest.2.log"
    cp "$QUALITY_LOG_DIR/pest.2.log" "$QUALITY_LOG_DIR/pest.2.fix1.log"
    run quality_gate_log_converged pest 2 1
    [ "$status" -eq 0 ]
}

@test "log_converged returns 1 when fix-1 differs from the initial run" {
    printf 'FAIL: pest test foo\n' > "$QUALITY_LOG_DIR/pest.2.log"
    printf 'FAIL: pest test foo (new error)\n' > "$QUALITY_LOG_DIR/pest.2.fix1.log"
    run quality_gate_log_converged pest 2 1
    [ "$status" -eq 1 ]
}

@test "log_converged compares fix-2 against fix-1, not the initial run" {
    printf 'A\n' > "$QUALITY_LOG_DIR/pest.2.log"
    printf 'B\n' > "$QUALITY_LOG_DIR/pest.2.fix1.log"
    cp "$QUALITY_LOG_DIR/pest.2.fix1.log" "$QUALITY_LOG_DIR/pest.2.fix2.log"
    run quality_gate_log_converged pest 2 2
    [ "$status" -eq 0 ]
}

@test "log_converged returns 1 when the previous file is missing" {
    printf 'whatever\n' > "$QUALITY_LOG_DIR/pest.2.fix1.log"
    run quality_gate_log_converged pest 2 1
    [ "$status" -eq 1 ]
}

@test "log_converged honours the artisan/artisan-smoke base mapping" {
    cp /dev/null "$QUALITY_LOG_DIR/artisan-smoke.1.log"
    cp /dev/null "$QUALITY_LOG_DIR/artisan-smoke.1.fix1.log"
    run quality_gate_log_converged artisan 1 1
    [ "$status" -eq 0 ]
}
