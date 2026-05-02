#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    # shellcheck source=../../lib/logging.sh
    source "$BATS_TEST_DIRNAME/../../lib/logging.sh"
}

@test "log_info schreibt nach stderr, nicht stdout" {
    run --separate-stderr bash -c 'source worker/lib/logging.sh; log_info "hello"'
    [ "$status" -eq 0 ]
    [ -z "$output" ]
    [[ "$stderr" == *"hello"* ]]
    [[ "$stderr" == *"INFO"* ]]
}

@test "log_error ist immer sichtbar, auch bei LOG_LEVEL=error" {
    LOG_LEVEL=error run --separate-stderr bash -c 'source worker/lib/logging.sh; log_error "boom"'
    [ "$status" -eq 0 ]
    [[ "$stderr" == *"boom"* ]]
    [[ "$stderr" == *"ERROR"* ]]
}

@test "log_debug ist bei LOG_LEVEL=info unsichtbar" {
    LOG_LEVEL=info run --separate-stderr bash -c 'source worker/lib/logging.sh; log_debug "secret"'
    [ "$status" -eq 0 ]
    [ -z "$stderr" ]
}

@test "log_debug ist bei LOG_LEVEL=debug sichtbar" {
    LOG_LEVEL=debug run --separate-stderr bash -c 'source worker/lib/logging.sh; log_debug "secret"'
    [ "$status" -eq 0 ]
    [[ "$stderr" == *"secret"* ]]
    [[ "$stderr" == *"DEBUG"* ]]
}

@test "log_warn unter LOG_LEVEL=error wird unterdrueckt" {
    LOG_LEVEL=error run --separate-stderr bash -c 'source worker/lib/logging.sh; log_warn "careful"'
    [ "$status" -eq 0 ]
    [ -z "$stderr" ]
}

@test "log_info enthaelt keine ANSI-Codes wenn stderr kein TTY ist" {
    run --separate-stderr bash -c 'source worker/lib/logging.sh; log_info "plain"'
    [[ "$stderr" != *$'\033['* ]]
}
