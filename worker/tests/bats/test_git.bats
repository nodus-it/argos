#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

# Integration tests for lib/git.sh (I2 — external branch collaboration). They
# drive a real local bare repo as "origin"; git_auth_header builds an
# http.extraheader that local-path remotes simply ignore, so a dummy token is
# fine. WORKSPACE_DIR + STATE_FILE are overridden to a tmp workspace.

setup() {
    unset REPO_URL REPO_PLATFORM
    # shellcheck source=../../worker/lib/logging.sh
    source worker/lib/logging.sh
    # shellcheck source=../../worker/lib/credentials.sh
    source worker/lib/credentials.sh
    # shellcheck source=../../worker/lib/state.sh
    source worker/lib/state.sh
    # shellcheck source=../../worker/lib/git.sh
    source worker/lib/git.sh

    export REPO_TOKEN="x:y" # contains ':' → git_auth_header uses it verbatim

    TESTDIR="$(mktemp -d)"
    export ORIGIN="$TESTDIR/origin.git"
    export WORKSPACE_DIR="$TESTDIR/ws"
    export STATE_FILE="$WORKSPACE_DIR/.agent/state.json"

    git init -q --bare "$ORIGIN"

    git clone -q "$ORIGIN" "$WORKSPACE_DIR"
    git -C "$WORKSPACE_DIR" config user.email t@example.com
    git -C "$WORKSPACE_DIR" config user.name tester
    echo base > "$WORKSPACE_DIR/base.txt"
    git -C "$WORKSPACE_DIR" add -A
    git -C "$WORKSPACE_DIR" commit -qm base
    git -C "$WORKSPACE_DIR" branch -M main
    git -C "$WORKSPACE_DIR" push -q origin main
    git -C "$WORKSPACE_DIR" checkout -qb feat/x
    echo impl > "$WORKSPACE_DIR/impl.txt"
    git -C "$WORKSPACE_DIR" add -A
    git -C "$WORKSPACE_DIR" commit -qm impl
    git -C "$WORKSPACE_DIR" push -q -u origin feat/x

    mkdir -p "$WORKSPACE_DIR/.agent"
    state_init "task-x" "$ORIGIN" "main"
    state_set_feature_branch "feat/x"
}

teardown() {
    rm -rf "$TESTDIR"
}

# _external_push: simulate a user cloning the feature branch, committing and
# pushing — the divergence git_sync must pick up.
_external_push() {
    local ext="$TESTDIR/ext"
    git clone -q -b feat/x "$ORIGIN" "$ext"
    git -C "$ext" config user.email u@example.com
    git -C "$ext" config user.name user
    echo external > "$ext/external.txt"
    git -C "$ext" add -A
    git -C "$ext" commit -qm "external user commit"
    git -C "$ext" push -q origin feat/x
    rm -rf "$ext"
}

# ── git_sync_feature_branch ──────────────────────────────────────────────────

@test "git_sync_feature_branch pulls external commits (remote-wins)" {
    _external_push
    [ ! -f "$WORKSPACE_DIR/external.txt" ] # not present before sync

    run git_sync_feature_branch
    [ "$status" -eq 0 ]
    [ -f "$WORKSPACE_DIR/external.txt" ] # external work now in the workspace
    [ -f "$WORKSPACE_DIR/impl.txt" ]     # earlier work preserved
}

@test "git_sync_feature_branch is a no-op when state has no feature branch" {
    state_set_feature_branch ""
    run git_sync_feature_branch
    [ "$status" -eq 0 ]
}

@test "git_sync_feature_branch keeps local state when remote branch is absent" {
    state_set_feature_branch "feat/never-pushed"
    run git_sync_feature_branch
    [ "$status" -eq 0 ]
    [ -f "$WORKSPACE_DIR/impl.txt" ] # local state untouched
}

# ── git_remote_branch_diverged ───────────────────────────────────────────────

@test "git_remote_branch_diverged is false when remote matches local" {
    run git_remote_branch_diverged "feat/x"
    [ "$status" -eq 1 ]
}

@test "git_remote_branch_diverged is true when remote is ahead" {
    _external_push
    run git_remote_branch_diverged "feat/x"
    [ "$status" -eq 0 ]
}

@test "git_remote_branch_diverged is false when remote branch is absent" {
    run git_remote_branch_diverged "feat/never-pushed"
    [ "$status" -eq 1 ]
}

@test "git_remote_branch_diverged is false for an empty branch arg" {
    run git_remote_branch_diverged ""
    [ "$status" -eq 1 ]
}
