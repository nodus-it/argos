#!/usr/bin/env bash
# lib/git.sh — shared git helpers for the worker phases.
#
# Centralises the "pull-before-run" logic (I2 — external branch collaboration):
# a user may check out the feature branch, push their own commits, and Argos
# must continue on that remote state instead of its stale local volume copy.
# Remote-wins: Argos pushes at the end of every phase, so its own last state is
# already part of the remote — resetting the workspace to the remote tip loses
# nothing local.
#
# WORKSPACE_DIR is overridable for tests (mirrors STATE_FILE in state.sh).

# shellcheck shell=bash

WORKSPACE_DIR="${WORKSPACE_DIR:-/workspace}"

# git_sync_feature_branch: Remote-wins sync of the workspace to the task's
# feature branch on origin, before a continue phase (respond / refine-implement)
# works on top. A missing remote branch (never pushed / deleted by the user) is
# not an error — the local state is kept.
#
# Args: none — reads the feature branch from state.json, the token from REPO_TOKEN
# Returns: 0 (synced or nothing-to-do), 1 on a hard git failure
git_sync_feature_branch() {
    local feature_branch auth_header
    feature_branch="$(state_get_feature_branch)"
    if [[ -z "$feature_branch" ]]; then
        log_warn "git: no feature branch in state — skipping pull-before-run"
        return 0
    fi

    set +x
    auth_header="$(git_auth_header "${REPO_TOKEN:-}")"

    log_info "git: pull-before-run — fetch origin ${feature_branch}"
    if ! git -C "$WORKSPACE_DIR" -c "http.extraheader=$auth_header" \
            fetch --quiet origin "$feature_branch" 2>/dev/null; then
        log_warn "git: origin/${feature_branch} not fetchable (never pushed?) — keeping local state"
        return 0
    fi

    log_info "git: checkout -B ${feature_branch} FETCH_HEAD (remote-wins)"
    if ! git -C "$WORKSPACE_DIR" checkout -B "$feature_branch" FETCH_HEAD; then
        echo "git: failed to reset workspace to origin/${feature_branch}" >&2
        return 1
    fi
    # -fd without -x: keep gitignored vendor/ and node_modules/ (as implement does).
    git -C "$WORKSPACE_DIR" clean -fd

    return 0
}

# git_remote_branch_diverged: True (exit 0) when origin/<feature_branch> carries
# commits that HEAD does not — someone pushed externally and a plain push would
# clobber it. False (exit 1) when the remote branch is absent or an ancestor of
# HEAD (a clean fast-forward push). Used by push.sh to fail with a clear message
# instead of a cryptic --force-with-lease reject.
#
# Args: =feature_branch
# Returns: 0 if diverged (must NOT push), 1 otherwise
git_remote_branch_diverged() {
    local feature_branch="${1:-}" auth_header
    [[ -z "$feature_branch" ]] && return 1

    set +x
    auth_header="$(git_auth_header "${REPO_TOKEN:-}")"

    if ! git -C "$WORKSPACE_DIR" -c "http.extraheader=$auth_header" \
            fetch --quiet origin "$feature_branch" 2>/dev/null; then
        # Remote branch does not exist yet — first push, not diverged.
        return 1
    fi

    # FETCH_HEAD is the remote tip. If it is an ancestor of HEAD, our push
    # fast-forwards; otherwise the remote carries external commits we'd lose.
    if git -C "$WORKSPACE_DIR" merge-base --is-ancestor FETCH_HEAD HEAD 2>/dev/null; then
        return 1
    fi

    return 0
}
