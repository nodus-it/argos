#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export AGENT_HOME="$TEST_DIR/.agent"
    export CLAUDE_TOKEN_FILE="$AGENT_HOME/claude_oauth_token"
    # shellcheck source=../../worker/lib/credentials.sh
    source worker/lib/credentials.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "credentials_save_claude_token persistiert Token mit Mode 600" {
    echo "sk-ant-oat01-secrettoken" | credentials_save_claude_token
    [ -f "$CLAUDE_TOKEN_FILE" ]
    perms="$(stat -c '%a' "$CLAUDE_TOKEN_FILE")"
    [ "$perms" = "600" ]
}

@test "credentials_save_claude_token lehnt leeren Token ab" {
    run --separate-stderr bash -c "
        export AGENT_HOME='$AGENT_HOME'
        export CLAUDE_TOKEN_FILE='$CLAUDE_TOKEN_FILE'
        source worker/lib/credentials.sh
        echo '' | credentials_save_claude_token
    "
    [ "$status" -ne 0 ]
    [[ "$stderr" == *"empty token refused"* ]]
}

@test "credentials_load_claude_token gibt Token auf stdout" {
    echo "sk-ant-oat01-foo" | credentials_save_claude_token
    out="$(credentials_load_claude_token)"
    [ "$out" = "sk-ant-oat01-foo" ]
}

@test "credentials_has_claude_token reflects file presence" {
    run credentials_has_claude_token
    [ "$status" -eq 1 ]
    echo "tok" | credentials_save_claude_token
    run credentials_has_claude_token
    [ "$status" -eq 0 ]
}

@test "credentials_save_task persistiert credentials.env Mode 600" {
    credentials_save_task task-001 "https://example.com/r.git" "ghp_abc" "main"
    file="$AGENT_HOME/tasks/task-001/credentials.env"
    [ -f "$file" ]
    perms="$(stat -c '%a' "$file")"
    [ "$perms" = "600" ]
}

@test "credentials_load_task setzt REPO_URL/REPO_TOKEN/BASE_BRANCH" {
    credentials_save_task task-001 "https://example.com/r.git" "ghp_abc" "main"
    credentials_load_task task-001
    [ "$REPO_URL" = "https://example.com/r.git" ]
    [ "$REPO_TOKEN" = "ghp_abc" ]
    [ "$BASE_BRANCH" = "main" ]
}

@test "credentials_load_task funktioniert mit Sonderzeichen im Token" {
    credentials_save_task task-001 "https://example.com/r.git" 'ghp_abc$with"weird&chars' "main"
    credentials_load_task task-001
    [ "$REPO_TOKEN" = 'ghp_abc$with"weird&chars' ]
}

@test "credentials_task_exists / credentials_delete_task" {
    run credentials_task_exists task-x
    [ "$status" -eq 1 ]
    credentials_save_task task-x url tok main
    run credentials_task_exists task-x
    [ "$status" -eq 0 ]
    credentials_delete_task task-x
    run credentials_task_exists task-x
    [ "$status" -eq 1 ]
}
