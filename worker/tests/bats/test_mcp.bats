#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export ARGOS_MCP_ENABLED=""
    export MCP_WORKSPACE_DIR="$TEST_DIR/workspace"
    mkdir -p "$MCP_WORKSPACE_DIR"
    # shellcheck source=../../lib/logging.sh
    source worker/lib/logging.sh
    # shellcheck source=../../lib/mcp.sh
    source worker/lib/mcp.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

# Helper: write a boost.json into the test workspace.
_write_boost_json() {
    local mcp_val="${1:-true}"
    printf '{"mcp":%s,"agents":["claude_code"]}\n' "$mcp_val" \
        > "$MCP_WORKSPACE_DIR/boost.json"
}

# ─── mcp_should_enable ────────────────────────────────────────────────────────

@test "mcp_should_enable: false wenn ARGOS_MCP_ENABLED nicht gesetzt" {
    _write_boost_json true
    run mcp_should_enable
    [ "$status" -ne 0 ]
}

@test "mcp_should_enable: false wenn ARGOS_MCP_ENABLED!='true'" {
    export ARGOS_MCP_ENABLED="false"
    _write_boost_json true
    run mcp_should_enable
    [ "$status" -ne 0 ]
}

@test "mcp_should_enable: false wenn boost.json fehlt" {
    export ARGOS_MCP_ENABLED="true"
    run mcp_should_enable
    [ "$status" -ne 0 ]
}

@test "mcp_should_enable: false wenn boost.json mcp=false" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json false
    run mcp_should_enable
    [ "$status" -ne 0 ]
}

@test "mcp_should_enable: false bei kaputtem boost.json" {
    export ARGOS_MCP_ENABLED="true"
    printf 'this is not json' > "$MCP_WORKSPACE_DIR/boost.json"
    run mcp_should_enable
    [ "$status" -ne 0 ]
}

@test "mcp_should_enable: true bei ARGOS_MCP_ENABLED=true und mcp=true" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    run mcp_should_enable
    [ "$status" -eq 0 ]
}

# ─── mcp_write_claude_config ──────────────────────────────────────────────────

@test "mcp_write_claude_config: schreibt Datei am Zielpfad" {
    local dest="$TEST_DIR/claude-state/mcp.json"
    run mcp_write_claude_config "$dest"
    [ "$status" -eq 0 ]
    [ -f "$dest" ]
}

@test "mcp_write_claude_config: legt fehlendes Verzeichnis an" {
    local dest="$TEST_DIR/new/nested/dir/mcp.json"
    [ ! -d "$(dirname "$dest")" ]
    run mcp_write_claude_config "$dest"
    [ "$status" -eq 0 ]
    [ -f "$dest" ]
}

@test "mcp_write_claude_config: korrekte JSON-Struktur fuer claude --mcp-config" {
    local dest="$TEST_DIR/mcp.json"
    mcp_write_claude_config "$dest"
    [ "$(jq -r '.mcpServers["laravel-boost"].type' "$dest")" = "stdio" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].command' "$dest")" = "php" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].args[0]' "$dest")" = "artisan" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].args[1]' "$dest")" = "boost:mcp" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].cwd' "$dest")" = "/workspace" ]
}

@test "mcp_write_claude_config: Fehler wenn dest fehlt" {
    run mcp_write_claude_config ""
    [ "$status" -ne 0 ]
}

# ─── mcp_codex_config_args ────────────────────────────────────────────────────

@test "mcp_codex_config_args: emittiert -c command-Override" {
    local out
    out="$(mcp_codex_config_args)"
    [[ "$out" == *'mcp_servers.laravel-boost.command="php"'* ]]
}

@test "mcp_codex_config_args: emittiert -c args-Override mit TOML-Array" {
    local out
    out="$(mcp_codex_config_args)"
    [[ "$out" == *'mcp_servers.laravel-boost.args=["artisan", "boost:mcp"]'* ]]
}

@test "mcp_codex_config_args: ein Token pro Zeile (mapfile-friendly)" {
    local arr=()
    mapfile -t arr < <(mcp_codex_config_args)
    # Two -c flags = 4 tokens (-c, key=val, -c, key=val)
    [ "${#arr[@]}" -eq 4 ]
    [ "${arr[0]}" = "-c" ]
    [ "${arr[2]}" = "-c" ]
}
