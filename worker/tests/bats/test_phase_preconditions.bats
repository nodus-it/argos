#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    mkdir -p /run/agent
    mkdir -p /workspace/.agent
    unset REPO_URL REPO_TOKEN BASE_BRANCH CLAUDE_CODE_OAUTH_TOKEN ITERATION PHASE_FLAGS

    # phase preconditions delegate the credential check to agent_auth_present
    # which lives in lib/agent.sh — must be sourced before the phase scripts.
    # shellcheck source=../../lib/agent.sh
    source worker/lib/agent.sh
    # shellcheck source=../../phases/concept.sh
    source worker/phases/concept.sh
    # shellcheck source=../../phases/implement.sh
    source worker/phases/implement.sh
    # shellcheck source=../../phases/diff.sh
    source worker/phases/diff.sh
    # shellcheck source=../../phases/push.sh
    source worker/phases/push.sh
    # shellcheck source=../../phases/commit-message.sh
    source worker/phases/commit-message.sh
    # shellcheck source=../../phases/respond.sh
    source worker/phases/respond.sh
}

teardown() {
    rm -rf /workspace/.git /workspace/.agent
    rm -f /run/agent/description.md
}

# --- concept ---

@test "concept: fehlt description.md → exit 2" {
    run --separate-stderr phase_concept_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"description.md fehlt"* ]]
}

@test "concept: fehlt REPO_URL → exit 2" {
    touch /run/agent/description.md
    export REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_concept_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"REPO_URL"* ]]
}

@test "concept: fehlt REPO_TOKEN → exit 2" {
    touch /run/agent/description.md
    export REPO_URL="https://example.com/r.git" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_concept_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"REPO_TOKEN"* ]]
}

@test "concept: fehlt BASE_BRANCH → exit 2" {
    touch /run/agent/description.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_concept_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"BASE_BRANCH"* ]]
}

@test "concept: fehlt CLAUDE_CODE_OAUTH_TOKEN → exit 3" {
    touch /run/agent/description.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main"
    run --separate-stderr phase_concept_preconditions
    [ "$status" -eq 3 ]
    [[ "$stderr" == *"CLAUDE_CODE_OAUTH_TOKEN"* ]]
}

@test "concept: alle Bedingungen erfuellt → exit 0" {
    touch /run/agent/description.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run phase_concept_preconditions
    [ "$status" -eq 0 ]
}

# --- implement ---

@test "implement: fehlt /workspace/.git → exit 2" {
    run --separate-stderr phase_implement_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"concept"* ]]
}

@test "implement: .git vorhanden, concept.md fehlt → exit 2" {
    mkdir -p /workspace/.git
    run --separate-stderr phase_implement_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"concept.md fehlt"* ]]
}

@test "implement: fehlt REPO_URL → exit 2" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md
    export REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_implement_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"REPO_URL"* ]]
}

@test "implement: fehlt CLAUDE_CODE_OAUTH_TOKEN → exit 3" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main"
    run --separate-stderr phase_implement_preconditions
    [ "$status" -eq 3 ]
    [[ "$stderr" == *"CLAUDE_CODE_OAUTH_TOKEN"* ]]
}

@test "implement: alle Bedingungen erfuellt → exit 0" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run phase_implement_preconditions
    [ "$status" -eq 0 ]
}

# --- diff ---

@test "diff: fehlt /workspace/.git → exit 2" {
    run --separate-stderr phase_diff_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"concept zuerst"* ]]
}

@test "diff: fehlt BASE_BRANCH → exit 2" {
    mkdir -p /workspace/.git
    run --separate-stderr phase_diff_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"BASE_BRANCH"* ]]
}

@test "diff: alle Bedingungen erfuellt → exit 0" {
    mkdir -p /workspace/.git
    export BASE_BRANCH="main"
    run phase_diff_preconditions
    [ "$status" -eq 0 ]
}

# --- push ---

@test "push: fehlt /workspace/.git → exit 2" {
    run --separate-stderr phase_push_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"nicht initialisiert"* ]]
}

@test "push: fehlt REPO_URL → exit 2" {
    mkdir -p /workspace/.git
    export REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_push_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"REPO_URL"* ]]
}

@test "push: fehlt CLAUDE_CODE_OAUTH_TOKEN → exit 3" {
    mkdir -p /workspace/.git
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main"
    run --separate-stderr phase_push_preconditions
    [ "$status" -eq 3 ]
    [[ "$stderr" == *"CLAUDE_CODE_OAUTH_TOKEN"* ]]
}

@test "push: alle Bedingungen erfuellt → exit 0" {
    mkdir -p /workspace/.git
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run phase_push_preconditions
    [ "$status" -eq 0 ]
}

# --- commit-message ---

@test "commit-message: fehlt /workspace/.git → exit 2" {
    run --separate-stderr phase_commit_message_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"nicht initialisiert"* ]]
}

@test "commit-message: fehlt CLAUDE_CODE_OAUTH_TOKEN → exit 3" {
    mkdir -p /workspace/.git
    run --separate-stderr phase_commit_message_preconditions
    [ "$status" -eq 3 ]
    [[ "$stderr" == *"CLAUDE_CODE_OAUTH_TOKEN"* ]]
}

@test "commit-message: alle Bedingungen erfuellt → exit 0" {
    mkdir -p /workspace/.git
    export CLAUDE_CODE_OAUTH_TOKEN="oat"
    run phase_commit_message_preconditions
    [ "$status" -eq 0 ]
}

# --- respond ---

@test "respond: fehlt /workspace/.git → exit 2" {
    run --separate-stderr phase_respond_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"nicht initialisiert"* ]]
}

@test "respond: .git vorhanden, concept.md fehlt → exit 2" {
    mkdir -p /workspace/.git
    run --separate-stderr phase_respond_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"concept.md fehlt"* ]]
}

@test "respond: concept.md vorhanden, respond.feedback.md fehlt → exit 2 mit UI-Hinweis" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md
    run --separate-stderr phase_respond_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"respond.feedback.md fehlt"* ]]
    [[ "$stderr" == *"UI"* ]]
}

@test "respond: fehlt REPO_URL → exit 2" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md /workspace/.agent/respond.feedback.md
    export REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run --separate-stderr phase_respond_preconditions
    [ "$status" -eq 2 ]
    [[ "$stderr" == *"REPO_URL"* ]]
}

@test "respond: fehlt CLAUDE_CODE_OAUTH_TOKEN → exit 3" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md /workspace/.agent/respond.feedback.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main"
    run --separate-stderr phase_respond_preconditions
    [ "$status" -eq 3 ]
    [[ "$stderr" == *"CLAUDE_CODE_OAUTH_TOKEN"* ]]
}

@test "respond: alle Bedingungen erfuellt → exit 0" {
    mkdir -p /workspace/.git
    touch /workspace/.agent/concept.md /workspace/.agent/respond.feedback.md
    export REPO_URL="https://example.com/r.git" REPO_TOKEN="tok" BASE_BRANCH="main" CLAUDE_CODE_OAUTH_TOKEN="oat"
    run phase_respond_preconditions
    [ "$status" -eq 0 ]
}
