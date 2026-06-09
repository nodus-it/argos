#!/usr/bin/env bash
# lib/credentials.sh — build git auth headers from a repo token.
#
# The worker receives its credentials as env vars from the manager
# (REPO_URL, REPO_TOKEN, BASE_BRANCH, REPO_PLATFORM) — it never persists
# tokens to disk. Never log the header output, never bake it into an image,
# never write it to a task volume.

# shellcheck shell=bash

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
