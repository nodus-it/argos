#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    unset REPO_URL REPO_TOKEN BASE_BRANCH REPO_PLATFORM
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

@test "_push_pr_gitlab extracts MR URL from push log" {
    tmplog="$(mktemp)"
    cat > "$tmplog" <<'EOF'
remote: To create a merge request for feat/my-feature, visit:
remote:   https://gitlab.com/acme/widget/-/merge_requests/42
EOF
    result="$(_push_pr_gitlab "$tmplog")"
    rm -f "$tmplog"
    [ "$result" = "https://gitlab.com/acme/widget/-/merge_requests/42" ]
}

@test "_push_pr_gitlab returns empty when no MR URL in log" {
    tmplog="$(mktemp)"
    echo "remote: Everything up-to-date" > "$tmplog"
    result="$(_push_pr_gitlab "$tmplog")"
    rm -f "$tmplog"
    [ "$result" = "" ]
}

@test "_push_pr_gitlab extracts self-hosted MR URL" {
    tmplog="$(mktemp)"
    cat > "$tmplog" <<'EOF'
remote:   https://git.example.com/team/project/-/merge_requests/7
EOF
    result="$(_push_pr_gitlab "$tmplog")"
    rm -f "$tmplog"
    [ "$result" = "https://git.example.com/team/project/-/merge_requests/7" ]
}
