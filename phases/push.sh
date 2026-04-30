#!/usr/bin/env bash
# phases/push.sh — Phase push: Branch zur Remote pushen.
#
# Ablauf:
#   1. Vorbedingungen pruefen (implement completed, Aenderungen vorhanden)
#   2. Sub-Phase commit-message aufrufen → produziert subject+body Files
#   3. git add -A && git commit -m "<subject>" -m "<body>"
#   4. git push -u origin <feature_branch>
#
# Cleanup-Frage stellt der CLI-Layer auf dem Host, nicht der Worker.

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

# _push_has_changes: Prueft ob es uncommitted Aenderungen oder lokale Commits ueber Base gibt.
# Returns: 0 wenn ja, 1 wenn nichts zu pushen ist.
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

# _push_create_github_pr: Erstellt einen GitHub Pull Request via gh CLI.
# Args: $1=feature_branch, $2=title, $3=body (optional)
# Output: PR-URL auf stdout, leer wenn nicht GitHub oder fehlgeschlagen.
_push_create_github_pr() {
    local feature_branch="$1"
    local title="$2"
    local body="${3:-}"

    [[ "$REPO_URL" == *"github.com"* ]] || return 0

    local pr_log="/workspace/.agent/logs/gh-pr.${ITERATION}.log"
    local pr_url
    set +x
    if ! pr_url="$(
        GITHUB_TOKEN="$REPO_TOKEN" \
        gh pr create \
            --title "$title" \
            --body "$body" \
            --base "$BASE_BRANCH" \
            --head "$feature_branch" \
            2>"$pr_log"
    )"; then
        if grep -q "already exists" "$pr_log" 2>/dev/null; then
            pr_url="$(GITHUB_TOKEN="$REPO_TOKEN" gh pr view "$feature_branch" \
                --json url --jq '.url' 2>/dev/null || true)"
            [[ -n "$pr_url" ]] && { printf '%s' "$pr_url"; return 0; }
        fi
        log_warn "push: PR-Erstellung fehlgeschlagen (siehe logs/gh-pr.${ITERATION}.log)"
        return 0
    fi
    printf '%s' "$pr_url"
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

    # No-changes-Pfad: kein Commit, kein Push
    if ! _push_has_changes; then
        log_warn "push: keine Aenderungen — nichts zu pushen"
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

    # Sub-Phase commit-message aufrufen
    log_info "push: rufe Sub-Phase commit-message auf"
    phase_load commit-message || {
        echo "push: konnte commit-message Sub-Phase nicht laden" >&2
        return 1
    }
    local cm_result_log="/workspace/.agent/logs/commit-message.result.${ITERATION}.json"
    if ! phase_commit_message_run > "$cm_result_log"; then
        echo "push: commit-message Sub-Phase fehlgeschlagen" >&2
        return 1
    fi

    local subject_file="/workspace/.agent/logs/commit-message.${ITERATION}.subject"
    local body_file="/workspace/.agent/logs/commit-message.${ITERATION}.body"
    if [[ ! -s "$subject_file" ]]; then
        echo "push: subject-File fehlt oder leer ($subject_file)" >&2
        return 1
    fi
    local subject body
    subject="$(cat "$subject_file")"
    body="$(cat "$body_file" 2>/dev/null || echo "")"

    # git identity setzen falls noch nicht gesetzt
    if [[ -z "$(git -C /workspace config --get user.email || true)" ]]; then
        git -C /workspace config user.email "agent@worker.local"
        git -C /workspace config user.name "Claude Worker Agent"
    fi

    # Stage + Commit (wenn unstaged Aenderungen vorhanden sind)
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

    # Push mit Token in der URL (defensiv: nicht loggen)
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
    if ! git -C /workspace push -u origin "$feature_branch" > "$push_log" 2>&1; then
        # URL ohne Token wiederherstellen vor return
        git -C /workspace remote set-url origin "$REPO_URL"
        local push_exit=1
        if grep -qE "401|403|denied|[Aa]uthentication" "$push_log"; then
            echo "push: Auth-Fehler beim git push (siehe $push_log)" >&2
            push_exit=3
        else
            echo "push: git push failed (siehe $push_log)" >&2
        fi
        result_emit \
            phase push \
            task_id "$TASK_ID" \
            --int iteration "$ITERATION" \
            status failed \
            started_at "$started_at" \
            finished_at "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" \
            --int exit_code "$push_exit" \
            error_message "git push failed (siehe ${push_log})"
        return "$push_exit"
    fi
    git -C /workspace remote set-url origin "$REPO_URL"

    local commit_sha
    commit_sha="$(git -C /workspace rev-parse HEAD)"

    # GitHub PR erstellen
    local pr_url=""
    pr_url="$(_push_create_github_pr "$feature_branch" "$subject" "$body")"
    if [[ -n "$pr_url" ]]; then
        log_info "push: PR erstellt — $pr_url"
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
