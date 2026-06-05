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

@test "log_scrub redacts Anthropic OAuth tokens" {
    out="$(printf 'hello sk-ant-oat01-AbCdEf123_-_world\n' | log_scrub)"
    [[ "$out" == *"[REDACTED:claude-oauth]"* ]]
    [[ "$out" != *"sk-ant-oat01-AbCdEf123"* ]]
}

@test "log_scrub redacts Anthropic API keys" {
    out="$(printf 'key sk-ant-api03-XYZ_abc123\n' | log_scrub)"
    [[ "$out" == *"[REDACTED:claude-api]"* ]]
    [[ "$out" != *"sk-ant-api03-XYZ"* ]]
}

@test "log_scrub redacts GitHub PATs" {
    out="$(printf 'token ghp_abcDEF123 and gho_xyz999\n' | log_scrub)"
    [[ "$out" == *"[REDACTED:github-pat]"* ]]
    [[ "$out" != *"ghp_abcDEF123"* ]]
    [[ "$out" != *"gho_xyz999"* ]]
}

@test "log_scrub redacts GitLab PATs" {
    out="$(printf 'token glpat-abc123_-XYZ\n' | log_scrub)"
    [[ "$out" == *"[REDACTED:gitlab-pat]"* ]]
    [[ "$out" != *"glpat-abc123"* ]]
}

@test "log_scrub redacts oauth2:<tok>@ in URLs" {
    out="$(printf 'clone https://oauth2:secret-token@github.com/foo/bar\n' | log_scrub)"
    [[ "$out" == *"oauth2:[REDACTED]@github.com"* ]]
    [[ "$out" != *"secret-token"* ]]
}

@test "log_scrub redacts Bearer/Basic Authorization headers" {
    out="$(printf 'Authorization: Bearer abc.def_123\nAuthorization: Basic Zm9vOmJhcg==\n' | log_scrub)"
    [[ "$out" == *"Authorization: Bearer [REDACTED]"* ]]
    [[ "$out" == *"Authorization: Basic [REDACTED]"* ]]
    [[ "$out" != *"abc.def_123"* ]]
    [[ "$out" != *"Zm9vOmJhcg=="* ]]
}

@test "log_scrub leaves harmless content untouched" {
    out="$(printf '{"type":"result","is_error":false}\nplain text line\n' | log_scrub)"
    [[ "$out" == *'{"type":"result","is_error":false}'* ]]
    [[ "$out" == *"plain text line"* ]]
}
