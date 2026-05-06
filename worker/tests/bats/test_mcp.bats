#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export CLAUDE_CONFIG_DIR="$TEST_DIR/claude-state"
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

@test "mcp_setup ist No-op wenn ARGOS_MCP_ENABLED nicht gesetzt" {
    _write_boost_json true
    mcp_setup
    [ ! -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup ist No-op wenn ARGOS_MCP_ENABLED=false" {
    export ARGOS_MCP_ENABLED="false"
    _write_boost_json true
    mcp_setup
    [ ! -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup ist No-op wenn boost.json fehlt" {
    export ARGOS_MCP_ENABLED="true"
    # MCP_WORKSPACE_DIR is set but has no boost.json
    mcp_setup
    [ ! -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup ist No-op wenn boost.json mcp=false hat" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json false
    mcp_setup
    [ ! -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup schreibt settings.json wenn ARGOS_MCP_ENABLED=true und mcp=true" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    mcp_setup
    [ -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup settings.json enthaelt korrekten MCP-Server-Eintrag" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    mcp_setup
    local settings="$CLAUDE_CONFIG_DIR/settings.json"
    [ "$(jq -r '.mcpServers["laravel-boost"].type' "$settings")" = "stdio" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].command' "$settings")" = "php" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].args[0]' "$settings")" = "artisan" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].args[1]' "$settings")" = "boost:mcp" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].cwd' "$settings")" = "/workspace" ]
}

@test "mcp_setup merged bestehende settings.json ohne vorhandene Keys zu loeschen" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    mkdir -p "$CLAUDE_CONFIG_DIR"
    printf '{"autoUpdaterStatus":"disabled","someOtherKey":"value"}\n' \
        > "$CLAUDE_CONFIG_DIR/settings.json"
    mcp_setup
    local settings="$CLAUDE_CONFIG_DIR/settings.json"
    [ "$(jq -r '.autoUpdaterStatus' "$settings")" = "disabled" ]
    [ "$(jq -r '.someOtherKey' "$settings")" = "value" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].type' "$settings")" = "stdio" ]
}

@test "mcp_setup ueberschreibt bestehenden laravel-boost Eintrag" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    mkdir -p "$CLAUDE_CONFIG_DIR"
    printf '{"mcpServers":{"laravel-boost":{"type":"http","url":"http://old"}}}\n' \
        > "$CLAUDE_CONFIG_DIR/settings.json"
    mcp_setup
    local settings="$CLAUDE_CONFIG_DIR/settings.json"
    [ "$(jq -r '.mcpServers["laravel-boost"].type' "$settings")" = "stdio" ]
    [ "$(jq -r '.mcpServers["laravel-boost"].url // "none"' "$settings")" = "none" ]
}

@test "mcp_setup legt CLAUDE_CONFIG_DIR an wenn er fehlt" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    export CLAUDE_CONFIG_DIR="$TEST_DIR/new-config-dir"
    [ ! -d "$CLAUDE_CONFIG_DIR" ]
    mcp_setup
    [ -d "$CLAUDE_CONFIG_DIR" ]
    [ -f "$CLAUDE_CONFIG_DIR/settings.json" ]
}

@test "mcp_setup gibt 0 zurueck wenn disabled" {
    export ARGOS_MCP_ENABLED="false"
    run mcp_setup
    [ "$status" -eq 0 ]
}

@test "mcp_setup gibt 0 zurueck nach erfolgreichem Setup" {
    export ARGOS_MCP_ENABLED="true"
    _write_boost_json true
    run mcp_setup
    [ "$status" -eq 0 ]
}
