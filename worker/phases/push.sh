#!/usr/bin/env bash
# phases/push.sh — push phase: push the feature branch to the remote.
#
# Steps:
#   1. check preconditions (implement completed, changes present)
#   2. invoke the commit-message sub-phase → produces subject + body files
#   3. git add -A && git commit -m "<subject>" -m "<body>"
#   4. git push -u origin <feature_branch>
#
# The cleanup prompt is asked by the host CLI, not by the worker.

# shellcheck shell=bash

phase_push_help() {
    echo "Push-Phase: erzeugt Commit-Message via Sub-Phase, committed, pusht zur Remote."
}

phase_push_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "push: /workspace nicht initialisiert." >&2
        return 2
    fi
    if [[ -z "${REPO_URL:-}" || -z "${REPO_TOKEN:-}" || -z "${BASE_BRANCH:-}" ]]; then
        echo "push: REPO_URL/REPO_TOKEN/BASE_BRANCH muessen gesetzt sein." >&2
        return 2
    fi
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "push: CLAUDE_CODE_OAUTH_TOKEN fehlt (commit-message braucht Claude)." >&2
        return 3
    fi
    return 0
}

# _push_has_changes: true if there are uncommitted changes or local commits ahead of base.
# Returns: 0 if yes, 1 if there is nothing to push.
_push_has_changes() {
    if [[ -n "$(git -C /workspace status --porcelain)" ]]; then
        return 0
    fi
    local base_ref="origin/$BASE_BRANCH"
    if git -C /workspace rev-parse --verify --quiet "$base_ref" >/dev/null; then
        local ahead
        ahead="$(git -C /workspace rev-list --count "$base_ref..HEAD" 2>/dev/null || echo 0)"
        if (( ahead > 0 )); then
            return 0
        fi
    fi
    return 1
}

# _push_detect_platform: print "github", "gitlab", "bitbucket", or "".
# Prefers the REPO_PLATFORM env var (set by the manager) so Self-Hosted GitLab
# instances with non-obvious hostnames are detected correctly.
_push_detect_platform() {
    if [[ -n "${REPO_PLATFORM:-}" ]]; then
        printf '%s' "$REPO_PLATFORM"
        return
    fi
    case "$REPO_URL" in
        *github.com*)    printf 'github' ;;
        *gitlab*)        printf 'gitlab' ;;
        *bitbucket.org*) printf 'bitbucket' ;;
        *)               printf '' ;;
    esac
}

# _push_pr_github: create a GitHub pull request via REST API.
# Args: $1=feature_branch, $2=title, $3=body (optional)
# Output: PR URL on stdout (empty on error).
_push_pr_github() {
    local feature_branch="$1"
    local title="$2"
    local body="${3:-}"

    local owner_repo
    owner_repo="$(printf '%s' "$REPO_URL" | sed 's|https://github.com/||; s|/$||; s|\.git$||')"
    [[ -n "$owner_repo" ]] || return 0

    local pr_log="/workspace/.agent/logs/gh-pr.${ITERATION}.log"
    local tmp_resp
    tmp_resp="$(mktemp)"

    set +x
    local http_code
    http_code="$(curl -s \
        -o "$tmp_resp" \
        -w '%{http_code}' \
        -X POST \
        -H "Authorization: Bearer $REPO_TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        "https://api.github.com/repos/$owner_repo/pulls" \
        -d "$(jq -n \
            --arg title "$title" \
            --arg body  "$body" \
            --arg base  "$BASE_BRANCH" \
            --arg head  "$feature_branch" \
            '{title:$title,body:$body,base:$base,head:$head}')" \
        2>"$pr_log")"

    local pr_url=""
    case "$http_code" in
        201)
            pr_url="$(jq -r '.html_url' "$tmp_resp")"
            ;;
        422)
            # PR already exists — look it up via the list API.
            local owner="${owner_repo%%/*}"
            pr_url="$(curl -s \
                -H "Authorization: Bearer $REPO_TOKEN" \
                -H "Accept: application/vnd.github+json" \
                -H "X-GitHub-Api-Version: 2022-11-28" \
                "https://api.github.com/repos/$owner_repo/pulls?head=${owner}:${feature_branch}&state=open" \
                2>>"$pr_log" \
                | jq -r '.[0].html_url // empty')"
            if [[ -n "$pr_url" ]]; then
                log_info "push: PR already exists — updating description ($pr_url)"
                _push_pr_update_github "$pr_url" "$title" "$body"
                pr_comment "$pr_url" "$(_push_build_iteration_comment)"
            else
                log_warn "push: PR already exists but URL could not be determined"
            fi
            ;;
        *)
            cat "$tmp_resp" >> "$pr_log"
            log_warn "push: PR creation failed (HTTP $http_code, see logs/gh-pr.${ITERATION}.log)"
            ;;
    esac

    rm -f "$tmp_resp"
    printf '%s' "$pr_url"
}

# _push_pr_gitlab: extract the MR URL from the git-push output. GitLab writes
# it to the remote-side output when `-o merge_request.create` is set.
# Args: $1=push_log
# Output: MR URL on stdout, empty if not found.
_push_pr_gitlab() {
    local push_log="$1"
    grep -oE 'https://[^[:space:]]*/merge_requests/[0-9]+' "$push_log" | head -1
}

# _push_pr_bitbucket: create a Bitbucket pull request via REST API.
# Supports both PAT (username:app_password → Basic Auth) and OAuth (Bearer).
# Args: $1=feature_branch, $2=title, $3=body (optional)
# Output: PR URL on stdout (empty on error).
_push_pr_bitbucket() {
    local feature_branch="$1"
    local title="$2"
    local body="${3:-}"

    # Extract workspace/slug from REPO_URL (https://bitbucket.org/workspace/slug[.git][/])
    local workspace_slug
    workspace_slug="$(printf '%s' "$REPO_URL" | sed 's|https://bitbucket.org/||; s|/$||; s|\.git$||')"
    [[ -n "$workspace_slug" ]] || return 0

    local workspace slug
    workspace="${workspace_slug%%/*}"
    slug="${workspace_slug#*/}"
    [[ -n "$workspace" && -n "$slug" ]] || return 0

    local pr_log="/workspace/.agent/logs/bb-pr.${ITERATION}.log"
    local tmp_resp
    tmp_resp="$(mktemp)"

    # Build auth header — PAT: "username:app_password" → Basic; OAuth: Bearer.
    local auth_header
    set +x
    if printf '%s' "$REPO_TOKEN" | grep -q ':'; then
        local encoded_creds
        encoded_creds="$(printf '%s' "$REPO_TOKEN" | base64 -w 0)"
        auth_header="Basic ${encoded_creds}"
    else
        auth_header="Bearer ${REPO_TOKEN}"
    fi

    local http_code
    http_code="$(curl -s \
        -o "$tmp_resp" \
        -w '%{http_code}' \
        -X POST \
        -H "Authorization: ${auth_header}" \
        -H "Content-Type: application/json" \
        "https://api.bitbucket.org/2.0/repositories/${workspace}/${slug}/pullrequests" \
        -d "$(jq -n \
            --arg title "$title" \
            --arg body  "$body" \
            --arg base  "$BASE_BRANCH" \
            --arg head  "$feature_branch" \
            '{title:$title,description:$body,source:{branch:{name:$head}},destination:{branch:{name:$base}}}')" \
        2>"$pr_log")"

    local pr_url=""
    case "$http_code" in
        201)
            pr_url="$(jq -r '.links.html.href' "$tmp_resp")"
            ;;
        409)
            # PR already exists — find it via the list endpoint.
            pr_url="$(curl -s \
                -H "Authorization: ${auth_header}" \
                "https://api.bitbucket.org/2.0/repositories/${workspace}/${slug}/pullrequests?q=source.branch.name+%3D+%22${feature_branch}%22+AND+state+%3D+%22OPEN%22" \
                2>>"$pr_log" \
                | jq -r '.values[0].links.html.href // empty')"
            if [[ -n "$pr_url" ]]; then
                log_info "push: Bitbucket PR already exists — $pr_url"
            else
                log_warn "push: Bitbucket PR already exists but URL could not be determined"
            fi
            ;;
        *)
            cat "$tmp_resp" >> "$pr_log"
            log_warn "push: Bitbucket PR creation failed (HTTP $http_code, see logs/bb-pr.${ITERATION}.log)"
            ;;
    esac

    rm -f "$tmp_resp"
    printf '%s' "$pr_url"
}

# _push_build_iteration_comment: build the comment text for a new implementation iteration.
_push_build_iteration_comment() {
    local nontechnical_file=/workspace/.agent/implement.summary.nontechnical.md
    {
        printf '## Update — Iteration %s\n\n' "$ITERATION"
        if [[ -f "$nontechnical_file" ]]; then
            cat "$nontechnical_file"
        else
            printf '_Keine Zusammenfassung vorhanden._'
        fi
    }
}

# _push_configure_repo_github: set squash-only merge and auto-delete-branch on the repo.
# Idempotent. Logs a warning on failure (e.g. missing admin rights) but does not abort.
_push_configure_repo_github() {
    local owner_repo
    owner_repo="$(printf '%s' "$REPO_URL" | sed 's|https://github.com/||; s|/$||; s|\.git$||')"
    [[ -n "$owner_repo" ]] || return 0

    set +x
    local http_code
    http_code="$(curl -s \
        -o /dev/null \
        -w '%{http_code}' \
        -X PATCH \
        -H "Authorization: Bearer $REPO_TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        "https://api.github.com/repos/$owner_repo" \
        -d "$(jq -n '{
            allow_squash_merge: true,
            allow_merge_commit: false,
            allow_rebase_merge: false,
            delete_branch_on_merge: true
        }')" 2>/dev/null)"
    if [[ "$http_code" == "200" ]]; then
        log_info "push: repo configured — squash-only, auto-delete branch on merge"
    else
        log_warn "push: repo-settings update skipped (HTTP $http_code) — squash/auto-delete not enforced"
    fi
}

# _push_pr_update_github: update the description of an existing GitHub PR.
# Args: $1=pr_url, $2=title, $3=body (optional)
_push_pr_update_github() {
    local pr_url="$1"
    local title="$2"
    local body="${3:-}"

    local owner_repo pr_number
    owner_repo="$(printf '%s' "$REPO_URL" | sed 's|https://github.com/||; s|/$||; s|\.git$||')"
    pr_number="$(printf '%s' "$pr_url" | grep -oE '[0-9]+$')"
    [[ -n "$owner_repo" && -n "$pr_number" ]] || return 0

    set +x
    curl -s \
        -X PATCH \
        -H "Authorization: Bearer $REPO_TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        "https://api.github.com/repos/$owner_repo/pulls/$pr_number" \
        -d "$(jq -n \
            --arg title "$title" \
            --arg body  "$body" \
            '{title:$title,body:$body}')" \
        >> "/workspace/.agent/logs/gh-pr.${ITERATION}.log" 2>&1 || true
}

phase_push_run() {
    cd /workspace 2>/dev/null || {
        echo "push: /workspace not mounted" >&2
        return 1
    }
    mkdir -p /workspace/.agent/logs

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    # No-changes path: skip commit and push.
    if ! _push_has_changes; then
        log_warn "push: no changes — nothing to push"
        finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
        finished_epoch=$(date -u +%s)
        local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

        result_emit \
            phase push \
            task_id "$TASK_ID" \
            --int iteration "$ITERATION" \
            status no_changes \
            started_at "$started_at" \
            finished_at "$finished_at" \
            --int duration_ms "$duration_ms" \
            --int exit_code 5
        return 5
    fi

    # Run the commit-message sub-phase.
    log_info "push: invoking commit-message sub-phase"
    phase_load commit-message || {
        echo "push: failed to load commit-message sub-phase" >&2
        return 1
    }
    local cm_result_log="/workspace/.agent/logs/commit-message.result.${ITERATION}.json"
    if ! phase_commit_message_run > "$cm_result_log"; then
        log_warn "push: commit-message failed — using fallback message"
        local _fc
        _fc="$(git -C /workspace status --porcelain | wc -l | tr -d ' ')"
        printf 'chore: apply implementation changes (%s files)\n' "$_fc" \
            > "/workspace/.agent/logs/commit-message.${ITERATION}.subject"
        : > "/workspace/.agent/logs/commit-message.${ITERATION}.body"
    fi

    local subject_file="/workspace/.agent/logs/commit-message.${ITERATION}.subject"
    local body_file="/workspace/.agent/logs/commit-message.${ITERATION}.body"
    if [[ ! -s "$subject_file" ]]; then
        echo "push: subject file missing or empty ($subject_file)" >&2
        return 1
    fi
    local subject body
    subject="$(cat "$subject_file")"
    body="$(cat "$body_file" 2>/dev/null || echo "")"

    # Set git identity if not already configured.
    if [[ -z "$(git -C /workspace config --get user.email || true)" ]]; then
        git -C /workspace config user.email "agent@worker.local"
        git -C /workspace config user.name "Claude Worker Agent"
    fi

    # Stage + commit (only if there are staged changes).
    git -C /workspace add -A
    if ! git -C /workspace diff --cached --quiet; then
        if [[ -n "$body" && "$body" != $'\n' ]]; then
            git -C /workspace commit -m "$subject" -m "$body" \
                > "/workspace/.agent/logs/git-commit.${ITERATION}.log" 2>&1
        else
            git -C /workspace commit -m "$subject" \
                > "/workspace/.agent/logs/git-commit.${ITERATION}.log" 2>&1
        fi
    fi

    # Detect the platform and prepare GitLab push options.
    local platform
    platform="$(_push_detect_platform)"

    local push_opts=()
    if [[ "$platform" == "gitlab" ]]; then
        push_opts+=(
            -o merge_request.create
            -o "merge_request.target=$BASE_BRANCH"
            -o "merge_request.title=$subject"
        )
        if [[ -n "$body" && "$body" != $'\n' ]]; then
            push_opts+=(-o "merge_request.description=$body")
        fi
    fi

    # Push with the token embedded in the URL (defensive: never log it).
    set +x
    local auth_url
    auth_url="$(git_auth_inject_token "$REPO_URL" "$REPO_TOKEN")"
    git -C /workspace remote set-url origin "$auth_url"

    local feature_branch
    feature_branch="$(state_get_feature_branch)"
    if [[ -z "$feature_branch" ]]; then
        feature_branch="$(git -C /workspace rev-parse --abbrev-ref HEAD)"
    fi

    local push_log="/workspace/.agent/logs/git-push.${ITERATION}.log"
    if ! git -C /workspace push -u --force-with-lease origin "$feature_branch" "${push_opts[@]}" > "$push_log" 2>&1; then
        # Restore the token-less URL before returning.
        git -C /workspace remote set-url origin "$REPO_URL"
        local push_exit=1
        if grep -qE "401|403|denied|[Aa]uthentication" "$push_log"; then
            echo "push: auth error on git push (see $push_log)" >&2
            push_exit=3
        else
            echo "push: git push failed (see $push_log)" >&2
        fi
        result_emit \
            phase push \
            task_id "$TASK_ID" \
            --int iteration "$ITERATION" \
            status failed \
            started_at "$started_at" \
            finished_at "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" \
            --int exit_code "$push_exit" \
            error_message "git push failed (see ${push_log})"
        return "$push_exit"
    fi
    git -C /workspace remote set-url origin "$REPO_URL"

    local commit_sha
    commit_sha="$(git -C /workspace rev-parse HEAD)"

    # PR body: implement.summary.nontechnical as the main body, the commit body as an addendum.
    local pr_body="$body"
    local _nontechnical_file=/workspace/.agent/implement.summary.nontechnical.md
    local _technical_file=/workspace/.agent/implement.summary.technical.md
    if [[ -f "$_nontechnical_file" ]]; then
        local _summary_content
        _summary_content="$(cat "$_nontechnical_file")"
        if [[ -n "$body" ]]; then
            pr_body="$(printf '%s\n\n---\n\n%s' "$_summary_content" "$body")"
        else
            pr_body="$_summary_content"
        fi
    fi
    if [[ -f "$_technical_file" ]]; then
        local _technical_content
        _technical_content="$(cat "$_technical_file")"
        pr_body="$(printf '%s\n\n<details><summary>Technische Details</summary>\n\n%s\n\n</details>' "$pr_body" "$_technical_content")"
    fi

    # Create the PR/MR or extract the URL from the push output.
    local pr_url=""
    case "$platform" in
        github)
            pr_url="$(_push_pr_github "$feature_branch" "$subject" "$pr_body")"
            _push_configure_repo_github
            ;;
        gitlab)     pr_url="$(_push_pr_gitlab "$push_log")" ;;
        bitbucket)  pr_url="$(_push_pr_bitbucket "$feature_branch" "$subject" "$pr_body")" ;;
    esac
    if [[ -n "$pr_url" ]]; then
        log_info "push: PR/MR created — $pr_url"
        state_set_pr_url "$pr_url"
    fi

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

    result_emit \
        phase push \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code 0 \
        branch "$feature_branch" \
        commit_sha "$commit_sha" \
        remote_url "$REPO_URL" \
        commit_subject "$subject" \
        pr_url "$pr_url"

    return 0
}
