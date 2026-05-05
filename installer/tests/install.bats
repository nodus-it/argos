#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    # Source install.sh — the source-guard at the bottom keeps main() from
    # firing when BASH_SOURCE != $0, so we get the helpers without any of the
    # docker / curl side-effects.
    # shellcheck source=../../install.sh
    source install.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# ── Secret generation ───────────────────────────────────────────────────────

@test "gen_password produces 32 alphanumeric chars" {
    local pw
    pw="$(gen_password)"
    [ "${#pw}" -eq 32 ]
    [[ "$pw" =~ ^[a-zA-Z0-9]+$ ]]
}

@test "gen_password produces different values on each call" {
    local a b
    a="$(gen_password)"
    b="$(gen_password)"
    [ "$a" != "$b" ]
}

@test "gen_app_key has the base64: prefix Laravel expects" {
    local key
    key="$(gen_app_key)"
    [[ "$key" == base64:* ]]
    # The encoded portion is 32 bytes -> 44 base64 chars (with padding).
    [ "${#key}" -eq 51 ]   # "base64:" (7) + 44 chars
}

# ── set_env_value / get_env_value ───────────────────────────────────────────

@test "set_env_value appends a missing key" {
    printf 'FOO=bar\n' > "$TEST_DIR/.env"
    set_env_value "$TEST_DIR/.env" BAZ "qux"
    grep -q '^BAZ=qux$' "$TEST_DIR/.env"
    grep -q '^FOO=bar$' "$TEST_DIR/.env"
}

@test "set_env_value replaces an existing key in place, leaves siblings alone" {
    printf 'FOO=bar\nBAZ=old\nQUX=keep\n' > "$TEST_DIR/.env"
    set_env_value "$TEST_DIR/.env" BAZ "new"
    grep -q '^BAZ=new$' "$TEST_DIR/.env"
    run grep -c '^BAZ=' "$TEST_DIR/.env"
    [ "$output" = "1" ]                            # exactly one BAZ line
    grep -q '^FOO=bar$' "$TEST_DIR/.env"
    grep -q '^QUX=keep$' "$TEST_DIR/.env"
}

@test "get_env_value reads simple values" {
    printf 'FOO=bar\nBAZ=qux quux\n' > "$TEST_DIR/.env"
    [ "$(get_env_value "$TEST_DIR/.env" FOO)" = "bar" ]
    [ "$(get_env_value "$TEST_DIR/.env" BAZ)" = "qux quux" ]
}

@test "get_env_value strips surrounding double quotes" {
    printf 'FOO="bar baz"\n' > "$TEST_DIR/.env"
    [ "$(get_env_value "$TEST_DIR/.env" FOO)" = "bar baz" ]
}

@test "get_env_value returns empty for an absent key" {
    printf 'FOO=bar\n' > "$TEST_DIR/.env"
    [ -z "$(get_env_value "$TEST_DIR/.env" MISSING)" ]
}

# ── merge_env_keys ──────────────────────────────────────────────────────────

@test "merge_env_keys appends new keys without touching existing values" {
    cat > "$TEST_DIR/.env.example" <<'EOF'
EXISTING_KEY=upstream-default
NEW_KEY=hello
EOF
    cat > "$TEST_DIR/.env" <<'EOF'
EXISTING_KEY=user-set-value
EOF
    merge_env_keys "$TEST_DIR/.env.example" "$TEST_DIR/.env" >/dev/null
    [ "$(get_env_value "$TEST_DIR/.env" EXISTING_KEY)" = "user-set-value" ]
    [ "$(get_env_value "$TEST_DIR/.env" NEW_KEY)"      = "hello" ]
}

@test "merge_env_keys ignores comment and blank lines in the example" {
    cat > "$TEST_DIR/.env.example" <<'EOF'
# section header

# inline comment
NEW_KEY=hello
EOF
    cat > "$TEST_DIR/.env" <<'EOF'
EXISTING=x
EOF
    merge_env_keys "$TEST_DIR/.env.example" "$TEST_DIR/.env" >/dev/null
    [ "$(get_env_value "$TEST_DIR/.env" NEW_KEY)" = "hello" ]
    # Two real lines (EXISTING= and NEW_KEY=), nothing else dragged in.
    run grep -cE '^[A-Z_][A-Z0-9_]*=' "$TEST_DIR/.env"
    [ "$output" = "2" ]
}

@test "merge_env_keys is a no-op when nothing is missing" {
    cat > "$TEST_DIR/.env.example" <<'EOF'
KEEP=upstream-default
EOF
    cat > "$TEST_DIR/.env" <<'EOF'
KEEP=user-value
EOF
    local before
    before="$(sha256_of "$TEST_DIR/.env")"
    merge_env_keys "$TEST_DIR/.env.example" "$TEST_DIR/.env" >/dev/null
    [ "$(sha256_of "$TEST_DIR/.env")" = "$before" ]
}

# ── sha256_of ───────────────────────────────────────────────────────────────

@test "sha256_of is deterministic and matches sha256sum" {
    printf 'hello\n' > "$TEST_DIR/file"
    local h
    h="$(sha256_of "$TEST_DIR/file")"
    [ "$h" = "5891b5b522d5df086d0ff0b110fbd9d21bb4fc7163af34d08286a2e846f6be03" ]
    [ "$h" = "$(sha256_of "$TEST_DIR/file")" ]
}

# ── Compose-modification refusal ────────────────────────────────────────────

@test "download_install_files refuses to clobber a locally-edited compose" {
    pushd "$TEST_DIR" >/dev/null

    INSTALL_DIR="$TEST_DIR"
    COMPOSE_FILE="docker-compose.yml"
    NGINX_FILE="nginx.conf"
    ENV_EXAMPLE_FILE=".env.example"
    STATE_DIR=".argos-state"

    # Simulate a previous install: pristine compose + matching recorded sha.
    printf 'pristine\n' > "$COMPOSE_FILE"
    mkdir -p "$STATE_DIR"
    sha256_of "$COMPOSE_FILE" > "$STATE_DIR/compose.sha256"

    # User edits the compose locally.
    printf 'pristine\n# user edit\n' > "$COMPOSE_FILE"

    # Point at file:// URLs that would normally succeed.
    RAW_BASE="file://$TEST_DIR/upstream"
    mkdir -p upstream
    printf 'new upstream content\n' > upstream/docker-compose.yml
    printf 'nginx upstream\n'       > upstream/nginx.conf
    printf 'KEY=val\n'              > upstream/.env.example

    run download_install_files
    [ "$status" -eq 1 ]
    [[ "$output" == *"modified locally"* ]]
    [[ "$output" == *"docker-compose.override.yml"* ]]

    popd >/dev/null
}
