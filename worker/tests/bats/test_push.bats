#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    unset REPO_URL REPO_TOKEN BASE_BRANCH REPO_PLATFORM
    # shellcheck source=../../worker/lib/logging.sh
    source worker/lib/logging.sh
    # shellcheck source=../../worker/lib/credentials.sh
    source worker/lib/credentials.sh
    # shellcheck source=../../worker/phases/push.sh
    source worker/phases/push.sh
}

# ── _push_detect_platform ────────────────────────────────────────────────────

@test "_push_detect_platform returns REPO_PLATFORM when set" {
    export REPO_PLATFORM="gitlab"
    export REPO_URL="https://custom.host.example.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "gitlab" ]
}

@test "_push_detect_platform REPO_PLATFORM=github takes precedence over URL" {
    export REPO_PLATFORM="github"
    export REPO_URL="https://gitlab.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "github" ]
}

@test "_push_detect_platform falls back to URL pattern for github.com" {
    unset REPO_PLATFORM
    export REPO_URL="https://github.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "github" ]
}

@test "_push_detect_platform falls back to URL pattern for gitlab.com" {
    unset REPO_PLATFORM
    export REPO_URL="https://gitlab.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "gitlab" ]
}

@test "_push_detect_platform returns empty for unknown URL without REPO_PLATFORM" {
    unset REPO_PLATFORM
    export REPO_URL="https://example.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "" ]
}

@test "_push_detect_platform self-hosted GitLab detected via REPO_PLATFORM" {
    export REPO_PLATFORM="gitlab"
    export REPO_URL="https://git.company.internal/team/project.git"
    result="$(_push_detect_platform)"
    [ "$result" = "gitlab" ]
}

# ── _push_pr_gitlab_create (MR via REST API, not push options) ────────────────

@test "_push_pr_gitlab_create posts to the MR API and returns the web_url" {
    export REPO_URL="https://gitlab.com/acme/widget.git"
    export REPO_TOKEN="tok"
    export BASE_BRANCH="main"
    export ITERATION=1
    mkdir -p /workspace/.agent/logs

    GL_CAPTURE_URL="$(mktemp)"
    curl() {
        local out="" prev=""
        for a in "$@"; do
            [[ "$prev" == "-o" ]] && out="$a"
            prev="$a"
        done
        printf '%s' "$out" > "$GL_CAPTURE_URL.dummy" 2>/dev/null || true
        [[ -n "$out" ]] && printf '{"web_url":"https://gitlab.com/acme/widget/-/merge_requests/7"}' > "$out"
        printf '201'
    }

    run _push_pr_gitlab_create "feat/x" "My title" "Some body"
    [ "$status" -eq 0 ]
    [ "$output" = "https://gitlab.com/acme/widget/-/merge_requests/7" ]
}

@test "_push_pr_gitlab_create sends a multi-line description as JSON (newline regression)" {
    export REPO_URL="https://gitlab.com/acme/widget.git"
    export REPO_TOKEN="tok"
    export BASE_BRANCH="main"
    export ITERATION=1
    mkdir -p /workspace/.agent/logs

    GL_PAYLOAD="$(mktemp)"
    curl() {
        local out="" payload="" prev=""
        for a in "$@"; do
            [[ "$prev" == "-o" ]] && out="$a"
            [[ "$prev" == "-d" ]] && payload="$a"
            prev="$a"
        done
        printf '%s' "$payload" > "$GL_PAYLOAD"
        [[ -n "$out" ]] && printf '{"web_url":"https://gitlab.com/acme/widget/-/merge_requests/8"}' > "$out"
        printf '201'
    }

    run _push_pr_gitlab_create "feat/x" "My title" "$(printf 'Line1\nLine2')"
    [ "$status" -eq 0 ]
    [ "$output" = "https://gitlab.com/acme/widget/-/merge_requests/8" ]

    # The whole point of the API path: a multi-line description survives as
    # valid JSON, where a git push option would have crashed with
    # "push options must not have new line characters".
    desc="$(jq -r '.description' < "$GL_PAYLOAD")"
    expected="$(printf 'Line1\nLine2')"
    [ "$desc" = "$expected" ]
    rm -f "$GL_PAYLOAD"
}

@test "_push_pr_gitlab_create encodes a nested project path for self-hosted GitLab" {
    export REPO_URL="https://git.example.com/group/sub/project.git"
    export REPO_TOKEN="tok"
    export BASE_BRANCH="develop"
    export ITERATION=1
    mkdir -p /workspace/.agent/logs

    GL_URL="$(mktemp)"
    curl() {
        local out="" prev=""
        for a in "$@"; do
            [[ "$prev" == "-o" ]] && out="$a"
            case "$a" in https://*/api/v4/*) printf '%s' "$a" > "$GL_URL" ;; esac
            prev="$a"
        done
        [[ -n "$out" ]] && printf '{"web_url":"https://git.example.com/group/sub/project/-/merge_requests/3"}' > "$out"
        printf '201'
    }

    run _push_pr_gitlab_create "feat/y" "Title" "body"
    [ "$status" -eq 0 ]
    [ "$(cat "$GL_URL")" = "https://git.example.com/api/v4/projects/group%2Fsub%2Fproject/merge_requests" ]
    rm -f "$GL_URL"
}
