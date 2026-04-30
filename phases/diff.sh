#!/usr/bin/env bash
# phases/diff.sh — Phase diff: Aenderungen anzeigen vor dem Push.
#
# Read-only. Liest `git diff origin/<base-branch>...HEAD` plus `git status`
# und druckt das auf stdout mit Farben wenn TTY. Optional --stat und
# --file=<path> via PHASE_FLAGS.
#
# Siehe WORKER-CONCEPT.md, Phase `diff`.

# shellcheck shell=bash

phase_diff_help() {
    echo "Diff-Phase: zeigt git diff origin/<base-branch>...HEAD auf stdout."
}

phase_diff_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "diff: /workspace nicht initialisiert (concept zuerst)." >&2
        return 2
    fi
    if [[ -z "${BASE_BRANCH:-}" ]]; then
        echo "diff: BASE_BRANCH nicht gesetzt." >&2
        return 2
    fi
    return 0
}

phase_diff_run() {
    cd /workspace 2>/dev/null || {
        echo "diff: /workspace not mounted" >&2
        return 1
    }
    mkdir -p /workspace/.agent/logs

    local stat_only file_only
    stat_only="$(echo "${PHASE_FLAGS:-}" | jq -r '.stat // false' 2>/dev/null || echo false)"
    file_only="$(echo "${PHASE_FLAGS:-}" | jq -r '.file // ""' 2>/dev/null || echo "")"

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    # Color/no-color je nach TTY
    local color_flag="--no-color"
    if [[ -t 1 ]]; then
        color_flag="--color=always"
    fi

    local base_ref="origin/$BASE_BRANCH"
    if ! git rev-parse --verify --quiet "$base_ref" >/dev/null; then
        log_warn "diff: $base_ref nicht bekannt, versuche fetch"
        set +x
        if [[ -n "${REPO_URL:-}" && -n "${REPO_TOKEN:-}" ]]; then
            local auth_url
            auth_url="$(git_auth_inject_token "$REPO_URL" "$REPO_TOKEN")"
            git remote set-url origin "$auth_url"
            git fetch --quiet origin "$BASE_BRANCH" || true
            git remote set-url origin "$REPO_URL"
        fi
    fi

    local diff_args=(diff "$color_flag" "${base_ref}...HEAD")
    if [[ "$stat_only" == "true" ]]; then
        diff_args+=(--stat)
    fi
    if [[ -n "$file_only" ]]; then
        diff_args+=(-- "$file_only")
    fi

    git "${diff_args[@]}" || true

    printf '\n--- git status ---\n'
    git status --short

    # Numstat fuer Result-JSON
    local files_changed=0 insertions=0 deletions=0
    if git rev-parse --verify --quiet "$base_ref" >/dev/null; then
        local numstat_output
        numstat_output="$(git diff --numstat "${base_ref}...HEAD" 2>/dev/null || echo "")"
        if [[ -n "$numstat_output" ]]; then
            files_changed="$(echo "$numstat_output" | wc -l)"
            insertions="$(echo "$numstat_output" | awk '{s+=$1} END {print s+0}')"
            deletions="$(echo "$numstat_output" | awk '{s+=$2} END {print s+0}')"
        fi
    fi

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

    result_emit \
        phase diff \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code 0 \
        --int files_changed "$files_changed" \
        --int insertions "$insertions" \
        --int deletions "$deletions"

    return 0
}
