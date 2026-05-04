#!/usr/bin/env bash
# pr.sh — provider-agnostic helpers for posting PR/MR comments from the worker.
#
# Each phase only ever calls `pr_comment <pr_url> <body>`; dispatch on
# REPO_PLATFORM (or, as fallback, the URL host) selects the right HTTP shape.
# Curl is invoked directly because the worker container does not host the
# Laravel app — the PHP-side GitProviderContract::commentOnPullRequest is the
# canonical implementation tested via the external suite, and these bash
# helpers must match its endpoint and body shape per provider.

# shellcheck shell=bash

# pr_comment: post a comment on the given PR/MR. Logs and never aborts —
# a failed comment is not worth tearing down a successful push for.
# Args: $1=pr_url, $2=comment_body
pr_comment() {
    local pr_url="$1" body="$2"
    [[ -n "$pr_url" && -n "$body" ]] || return 0

    local platform
    platform="$(_pr_detect_platform "$pr_url")"
    case "$platform" in
        github)    _pr_comment_github    "$pr_url" "$body" ;;
        gitlab)    _pr_comment_gitlab    "$pr_url" "$body" ;;
        bitbucket) _pr_comment_bitbucket "$pr_url" "$body" ;;
        *)
            log_warn "pr_comment: unbekannte Plattform für URL '$pr_url' — Kommentar nicht gepostet"
            return 0
            ;;
    esac
}

# _pr_detect_platform: prefer REPO_PLATFORM (set by the manager — covers
# self-hosted GitLab), fall back to URL pattern matching.
# Args: $1=pr_url
# Output: "github" | "gitlab" | "bitbucket" | ""
_pr_detect_platform() {
    local url="$1"
    if [[ -n "${REPO_PLATFORM:-}" ]]; then
        printf '%s' "$REPO_PLATFORM"
        return
    fi
    case "$url" in
        *github.com*)    printf 'github' ;;
        *bitbucket.org*) printf 'bitbucket' ;;
        *"/-/merge_requests/"*) printf 'gitlab' ;;
        *)               printf '' ;;
    esac
}

# _pr_comment_github: POST /repos/{owner}/{repo}/issues/{n}/comments {body}.
# GitHub treats PRs as a flavour of issues — same endpoint as issue comments.
_pr_comment_github() {
    local pr_url="$1" body="$2"
    local owner_repo pr_number
    owner_repo="$(printf '%s' "$REPO_URL" | sed 's|https://github.com/||; s|/$||; s|\.git$||')"
    pr_number="$(printf '%s' "$pr_url" | grep -oE '[0-9]+$')"
    [[ -n "$owner_repo" && -n "$pr_number" ]] || {
        log_warn "pr_comment(github): owner/repo oder pr_number nicht extrahierbar"
        return 0
    }

    set +x
    curl -s \
        -X POST \
        -H "Authorization: Bearer $REPO_TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        "https://api.github.com/repos/${owner_repo}/issues/${pr_number}/comments" \
        -d "$(jq -cn --arg body "$body" '{body:$body}')" \
        >> "/workspace/.agent/logs/pr-comment.${ITERATION:-0}.log" 2>&1 || true
}

# _pr_comment_gitlab: POST {instance}/api/v4/projects/{enc}/merge_requests/{iid}/notes {body}.
# Project path is URL-encoded; iid is the last numeric segment of the MR URL.
_pr_comment_gitlab() {
    local pr_url="$1" body="$2"
    local instance project iid project_enc
    # Strip the well-known "/-/merge_requests/N" suffix to separate base and id.
    instance="$(printf '%s' "$pr_url" | sed -E 's|^(https?://[^/]+)/.*|\1|')"
    iid="$(printf '%s' "$pr_url" | grep -oE '/-/merge_requests/[0-9]+' | grep -oE '[0-9]+$')"
    project="$(printf '%s' "$pr_url" | sed -E 's|^https?://[^/]+/||; s|/-/merge_requests/.*||')"
    [[ -n "$instance" && -n "$project" && -n "$iid" ]] || {
        log_warn "pr_comment(gitlab): instance/project/iid nicht extrahierbar aus '$pr_url'"
        return 0
    }
    # rawurlencode the project path: replace '/' with %2F (only character we expect)
    project_enc="${project//\//%2F}"

    set +x
    curl -s \
        -X POST \
        -H "Authorization: Bearer $REPO_TOKEN" \
        -H "Content-Type: application/json" \
        "${instance}/api/v4/projects/${project_enc}/merge_requests/${iid}/notes" \
        -d "$(jq -cn --arg body "$body" '{body:$body}')" \
        >> "/workspace/.agent/logs/pr-comment.${ITERATION:-0}.log" 2>&1 || true
}

# _pr_comment_bitbucket: POST /repositories/{ws}/{repo}/pullrequests/{id}/comments
# {content:{raw:body}}. Auth dispatches on token shape: token containing ":"
# is "user:secret" (Basic), else Bearer (Repository Access Token / OAuth).
_pr_comment_bitbucket() {
    local pr_url="$1" body="$2"
    local workspace_slug pr_id workspace slug
    workspace_slug="$(printf '%s' "$pr_url" | sed -E 's|^https?://bitbucket\.org/||; s|/pull-requests/.*||')"
    pr_id="$(printf '%s' "$pr_url" | grep -oE '/pull-requests/[0-9]+' | grep -oE '[0-9]+$')"
    workspace="${workspace_slug%%/*}"
    slug="${workspace_slug#*/}"
    [[ -n "$workspace" && -n "$slug" && -n "$pr_id" ]] || {
        log_warn "pr_comment(bitbucket): workspace/slug/id nicht extrahierbar aus '$pr_url'"
        return 0
    }

    local auth_header
    set +x
    if printf '%s' "$REPO_TOKEN" | grep -q ':'; then
        local encoded
        encoded="$(printf '%s' "$REPO_TOKEN" | base64 -w 0)"
        auth_header="Basic ${encoded}"
    else
        auth_header="Bearer ${REPO_TOKEN}"
    fi

    curl -s \
        -X POST \
        -H "Authorization: ${auth_header}" \
        -H "Content-Type: application/json" \
        "https://api.bitbucket.org/2.0/repositories/${workspace}/${slug}/pullrequests/${pr_id}/comments" \
        -d "$(jq -cn --arg body "$body" '{content:{raw:$body}}')" \
        >> "/workspace/.agent/logs/pr-comment.${ITERATION:-0}.log" 2>&1 || true
}
