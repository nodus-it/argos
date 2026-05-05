#!/usr/bin/env bash
# lib/credentials.sh — host-side storage for tokens and repo credentials.
#
# Sensitive data lives under $AGENT_HOME (default ~/.agent) with mode 600.
# Never log, never bake into an image, never write to a task volume.
#
# Host layout:
#   $AGENT_HOME/claude_oauth_token              (mode 600)
#   $AGENT_HOME/tasks/<task-id>/credentials.env (mode 600)
#       contents: REPO_URL=, REPO_TOKEN=, BASE_BRANCH=

# shellcheck shell=bash

AGENT_HOME="${AGENT_HOME:-$HOME/.agent}"
CLAUDE_TOKEN_FILE="${CLAUDE_TOKEN_FILE:-$AGENT_HOME/claude_oauth_token}"

# _credentials_atomic_write: atomically write stdin to $1 with mode 600.
# Args: $1=target_path
_credentials_atomic_write() {
    local target="$1"
    mkdir -p "$(dirname "$target")"
    local tmp
    tmp="$(mktemp "${target}.XXXXXX")"
    chmod 600 "$tmp"
    cat > "$tmp"
    mv "$tmp" "$target"
    chmod 600 "$target"
}

# credentials_save_claude_token: persist the OAuth token from stdin.
# Stdin: token string (e.g. sk-ant-oat01-...)
credentials_save_claude_token() {
    local token
    token="$(cat)"
    if [[ -z "$token" ]]; then
        echo "credentials_save_claude_token: empty token refused" >&2
        return 1
    fi
    printf '%s\n' "$token" | _credentials_atomic_write "$CLAUDE_TOKEN_FILE"
}

# credentials_load_claude_token: print the stored token to stdout.
# Returns: 0 if a token is present, 1 otherwise.
credentials_load_claude_token() {
    if [[ ! -f "$CLAUDE_TOKEN_FILE" ]]; then
        echo "credentials_load_claude_token: $CLAUDE_TOKEN_FILE not found" >&2
        return 1
    fi
    head -n1 "$CLAUDE_TOKEN_FILE"
}

# credentials_has_claude_token: true if the token file exists and is non-empty.
credentials_has_claude_token() {
    [[ -s "$CLAUDE_TOKEN_FILE" ]]
}

# _credentials_task_path: host directory of a task.
# Args: $1=task_id
_credentials_task_path() {
    echo "$AGENT_HOME/tasks/$1"
}

# credentials_save_task: write credentials.env for a task.
# Args: $1=task_id, $2=repo_url, $3=repo_token, $4=base_branch, $5=repo_platform (optional)
credentials_save_task() {
    local task_id="$1"
    local repo_url="$2"
    local repo_token="$3"
    local base_branch="$4"
    local repo_platform="${5:-}"
    local dir
    dir="$(_credentials_task_path "$task_id")"
    mkdir -p "$dir"
    chmod 700 "$dir"
    {
        printf 'REPO_URL=%q\n' "$repo_url"
        printf 'REPO_TOKEN=%q\n' "$repo_token"
        printf 'BASE_BRANCH=%q\n' "$base_branch"
        [[ -n "$repo_platform" ]] && printf 'REPO_PLATFORM=%q\n' "$repo_platform"
    } | _credentials_atomic_write "$dir/credentials.env"
}

# credentials_load_task: source credentials.env of a task into the current shell.
# Args: $1=task_id
# Side effect: sets REPO_URL, REPO_TOKEN, BASE_BRANCH, REPO_PLATFORM (if present).
# Returns: 0 if loaded, 1 if the file is missing.
credentials_load_task() {
    local task_id="$1"
    local file
    file="$(_credentials_task_path "$task_id")/credentials.env"
    if [[ ! -f "$file" ]]; then
        echo "credentials_load_task: $file not found" >&2
        return 1
    fi
    # shellcheck disable=SC1090
    source "$file"
}

# credentials_delete_task: remove the task directory entirely (idempotent).
# Args: $1=task_id
credentials_delete_task() {
    local task_id="$1"
    rm -rf "$(_credentials_task_path "$task_id")"
}

# credentials_task_exists: true if a credentials entry exists for this task.
# Args: $1=task_id
credentials_task_exists() {
    local task_id="$1"
    [[ -f "$(_credentials_task_path "$task_id")/credentials.env" ]]
}

# git_auth_header: build the value for `http.extraheader` so git can authenticate
# without persisting the token inside the workspace (no token in origin URL,
# no token written to .git/config).
# Args: $1=token (plain PAT/OAuth, or "user:pass" form for Bitbucket app passwords)
#       $2=platform (optional: "github"|"gitlab"|"bitbucket") — falls back to
#          REPO_PLATFORM, then REPO_URL host inspection.
# Output: header line on stdout, e.g. "Authorization: Basic <base64>".
# Usage: git -c "http.extraheader=$(git_auth_header "$REPO_TOKEN")" <command>
# Note: never log the output — it carries the secret.
#
# Bitbucket-OAuth-Tokens werden vom Server nur akzeptiert, wenn der User-Teil
# `x-token-auth` lautet — `oauth2:<token>` (das GitHub/GitLab klaglos schlucken)
# wird mit "Authentication failed" abgewiesen. App-Passwörter und Atlassian-API-
# Tokens kommen schon in der Form "user:secret" und werden 1:1 verwendet.
git_auth_header() {
    local token="$1"
    local platform="${2:-${REPO_PLATFORM:-}}"
    if [[ -z "$platform" ]]; then
        case "${REPO_URL:-}" in
            *bitbucket.org*) platform="bitbucket" ;;
            *github.com*)    platform="github" ;;
        esac
    fi
    local creds
    if printf '%s' "$token" | grep -q ':'; then
        creds="$token"
    elif [[ "$platform" == "bitbucket" ]]; then
        creds="x-token-auth:$token"
    else
        creds="oauth2:$token"
    fi
    printf 'Authorization: Basic %s' "$(printf '%s' "$creds" | base64 -w 0)"
}
