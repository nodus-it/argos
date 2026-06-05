#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    # shellcheck source=../../worker/lib/credentials.sh
    source worker/lib/credentials.sh
}

@test "git_auth_header builds Basic header with oauth2: prefix for plain token" {
    out="$(git_auth_header "ghp_secrettoken123")"
    [[ "$out" == "Authorization: Basic "* ]]
    # Decoding the base64 part should yield "oauth2:<token>"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "oauth2:ghp_secrettoken123" ]
}

@test "git_auth_header keeps user:pass form for Bitbucket app passwords" {
    out="$(git_auth_header "alice:apppass-xyz")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "alice:apppass-xyz" ]
}

@test "git_auth_header output does not leak the raw token" {
    out="$(git_auth_header "ghp_LITERAL_TOKEN")"
    [[ "$out" != *"ghp_LITERAL_TOKEN"* ]]
}

@test "git_auth_header uses x-token-auth: prefix when REPO_PLATFORM=bitbucket" {
    export REPO_PLATFORM="bitbucket"
    out="$(git_auth_header "atlassian_oauth_token")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "x-token-auth:atlassian_oauth_token" ]
}

@test "git_auth_header detects bitbucket via REPO_URL when REPO_PLATFORM unset" {
    unset REPO_PLATFORM
    export REPO_URL="https://bitbucket.org/ws/repo.git"
    out="$(git_auth_header "atlassian_oauth_token")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "x-token-auth:atlassian_oauth_token" ]
}

@test "git_auth_header keeps user:pass form for Bitbucket Atlassian API tokens" {
    export REPO_PLATFORM="bitbucket"
    out="$(git_auth_header "user@example.com:atlassian_api_token")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "user@example.com:atlassian_api_token" ]
}

@test "git_auth_header explicit platform arg overrides env" {
    export REPO_PLATFORM="github"
    out="$(git_auth_header "tok" "bitbucket")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "x-token-auth:tok" ]
}

@test "git_auth_header keeps oauth2: prefix for github even with bitbucket-shaped tokens" {
    unset REPO_PLATFORM
    export REPO_URL="https://github.com/foo/bar.git"
    out="$(git_auth_header "ghp_token")"
    encoded="${out#Authorization: Basic }"
    decoded="$(printf '%s' "$encoded" | base64 -d)"
    [ "$decoded" = "oauth2:ghp_token" ]
}
