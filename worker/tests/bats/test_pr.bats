#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    unset REPO_URL REPO_TOKEN REPO_PLATFORM ITERATION
    mkdir -p /workspace/.agent/logs

    # shellcheck source=../../lib/logging.sh
    source worker/lib/logging.sh
    # shellcheck source=../../lib/pr.sh
    source worker/lib/pr.sh

    # Capture every curl arg, one per line, into a single file. Tests `grep -F`
    # for the strings they care about. Multi-call tests can append a separator
    # arg manually.
    curl_log="$BATS_TEST_TMPDIR/curl_args"
    : > "$curl_log"
    export curl_log
    curl() {
        printf '%s\n' "$@" >> "$curl_log"
        return 0
    }
    export -f curl
}

teardown() {
    rm -rf /workspace/.agent
}

# ── _pr_detect_platform ───────────────────────────────────────────────────────

@test "_pr_detect_platform: REPO_PLATFORM überrides URL" {
    export REPO_PLATFORM="gitlab"
    [ "$(_pr_detect_platform 'https://github.com/x/y/pull/1')" = "gitlab" ]
}

@test "_pr_detect_platform: github.com URL → github" {
    [ "$(_pr_detect_platform 'https://github.com/acme/widget/pull/42')" = "github" ]
}

@test "_pr_detect_platform: bitbucket.org URL → bitbucket" {
    [ "$(_pr_detect_platform 'https://bitbucket.org/ws/repo/pull-requests/3')" = "bitbucket" ]
}

@test "_pr_detect_platform: GitLab MR pattern → gitlab (auch self-hosted)" {
    [ "$(_pr_detect_platform 'https://git.firma.de/grp/proj/-/merge_requests/7')" = "gitlab" ]
    [ "$(_pr_detect_platform 'https://gitlab.com/x/y/-/merge_requests/9')" = "gitlab" ]
}

@test "_pr_detect_platform: unbekannte URL ohne REPO_PLATFORM → leer" {
    [ "$(_pr_detect_platform 'https://example.com/x/y/pulls/1')" = "" ]
}

# ── pr_comment dispatcher ─────────────────────────────────────────────────────

@test "pr_comment: leerer Body oder leere URL → no-op (kein curl-Call)" {
    pr_comment "" "body"
    pr_comment "https://github.com/x/y/pull/1" ""
    [ ! -s "$curl_log" ]
}

@test "pr_comment: unbekannte Plattform → log_warn, kein curl" {
    unset REPO_PLATFORM
    pr_comment "https://unknown.example.com/foo/bar/pulls/1" "hi"
    [ ! -s "$curl_log" ]
}

# ── _pr_comment_github ────────────────────────────────────────────────────────

@test "_pr_comment_github: korrekter Endpoint und Body" {
    export REPO_URL="https://github.com/acme/widget.git"
    export REPO_TOKEN="ghp_token"
    export ITERATION=2

    pr_comment "https://github.com/acme/widget/pull/42" "Hallo Welt"

    grep -qF 'https://api.github.com/repos/acme/widget/issues/42/comments' "$curl_log"
    grep -qF 'Authorization: Bearer ghp_token' "$curl_log"
    grep -qF '"body":"Hallo Welt"' "$curl_log"
}

@test "_pr_comment_github: extrahiert PR-Nummer aus URL" {
    export REPO_URL="https://github.com/acme/widget.git"
    export REPO_TOKEN="ghp_token"

    pr_comment "https://github.com/acme/widget/pull/7" "x"

    grep -qF '/issues/7/comments' "$curl_log"
}

# ── _pr_comment_gitlab ────────────────────────────────────────────────────────

@test "_pr_comment_gitlab: korrekter Endpoint inkl. URL-encoded project" {
    export REPO_URL="https://gitlab.com/grp/proj.git"
    export REPO_TOKEN="glpat-token"

    pr_comment "https://gitlab.com/grp/proj/-/merge_requests/7" "kommentar"

    grep -qF 'https://gitlab.com/api/v4/projects/grp%2Fproj/merge_requests/7/notes' "$curl_log"
    grep -qF 'Authorization: Bearer glpat-token' "$curl_log"
    grep -qF '"body":"kommentar"' "$curl_log"
}

@test "_pr_comment_gitlab: self-hosted Instanz wird respektiert" {
    export REPO_URL="https://git.firma.de/grp/proj.git"
    export REPO_TOKEN="glpat-token"
    export REPO_PLATFORM="gitlab"

    pr_comment "https://git.firma.de/grp/proj/-/merge_requests/9" "x"

    grep -qF 'https://git.firma.de/api/v4/projects/grp%2Fproj/merge_requests/9/notes' "$curl_log"
}

# ── _pr_comment_bitbucket ─────────────────────────────────────────────────────

@test "_pr_comment_bitbucket: Bearer-Auth bei Token ohne Doppelpunkt" {
    export REPO_URL="https://bitbucket.org/ws/repo"
    export REPO_TOKEN="ATCTT3xFf-no-colon-token"

    pr_comment "https://bitbucket.org/ws/repo/pull-requests/3" "kommentar"

    grep -qF 'https://api.bitbucket.org/2.0/repositories/ws/repo/pullrequests/3/comments' "$curl_log"
    grep -qF 'Authorization: Bearer ATCTT3xFf-no-colon-token' "$curl_log"
    grep -qF '"raw":"kommentar"' "$curl_log"
}

@test "_pr_comment_bitbucket: Basic-Auth bei Token mit Doppelpunkt" {
    export REPO_URL="https://bitbucket.org/ws/repo"
    export REPO_TOKEN="user:secret"

    pr_comment "https://bitbucket.org/ws/repo/pull-requests/3" "x"

    expected_b64="$(printf '%s' 'user:secret' | base64 -w 0)"
    grep -qF "Authorization: Basic ${expected_b64}" "$curl_log"
}

@test "_pr_comment_bitbucket: Body wird in content.raw verpackt" {
    export REPO_URL="https://bitbucket.org/ws/repo"
    export REPO_TOKEN="tok"

    pr_comment "https://bitbucket.org/ws/repo/pull-requests/5" "der inhalt"

    grep -qF '"content":{"raw":"der inhalt"}' "$curl_log"
}
