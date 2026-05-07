#!/usr/bin/env bash
# phases/diff.sh — diff phase: show pending changes before push.
#
# Read-only. Prints `git diff origin/<base-branch>...HEAD` plus `git status`
# to stdout (coloured on a TTY). Optional --stat and --file=<path> via PHASE_FLAGS.

# shellcheck shell=bash

phase_diff_help() {
    echo "Diff-Phase: zeigt git diff origin/<base-branch>...HEAD auf stdout."
}

phase_diff_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        # User-facing CLI message kept in German for consistency with the other phases.
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

    # Colour only when stdout is a TTY.
    local color_flag="--no-color"
    if [[ -t 1 ]]; then
        color_flag="--color=always"
    fi

    local base_ref="origin/$BASE_BRANCH"
    if ! git rev-parse --verify --quiet "$base_ref" >/dev/null; then
        log_warn "diff: $base_ref not known locally, attempting fetch"
        set +x
        if [[ -n "${REPO_URL:-}" && -n "${REPO_TOKEN:-}" ]]; then
            local auth_header
            auth_header="$(git_auth_header "$REPO_TOKEN")"
            git -c "http.extraheader=$auth_header" fetch --quiet origin "$BASE_BRANCH" || true
        fi
    fi

    # Compare working tree against base — implement leaves changes uncommitted
    # (push commits later). 3-dot ${base}...HEAD would only show committed
    # changes and miss everything Claude just produced.
    local diff_args=(diff "$color_flag" "${base_ref}")
    if [[ "$stat_only" == "true" ]]; then
        diff_args+=(--stat)
    fi
    if [[ -n "$file_only" ]]; then
        diff_args+=(-- "$file_only")
    fi

    git "${diff_args[@]}" || true

    printf '\n--- git status ---\n'
    git status --short

    # Numstat for the result JSON.
    local files_changed=0 insertions=0 deletions=0
    if git rev-parse --verify --quiet "$base_ref" >/dev/null; then
        local numstat_output
        numstat_output="$(git diff --numstat "${base_ref}" 2>/dev/null || echo "")"
        if [[ -n "$numstat_output" ]]; then
            files_changed="$(echo "$numstat_output" | wc -l)"
            insertions="$(echo "$numstat_output" | awk '{s+=$1} END {print s+0}')"
            deletions="$(echo "$numstat_output" | awk '{s+=$2} END {print s+0}')"
        fi
    fi

    # Untracked (new) files are invisible to git diff --numstat; count them separately.
    local untracked_files
    untracked_files="$(git ls-files --others --exclude-standard 2>/dev/null)"
    if [[ -n "$untracked_files" ]]; then
        local untracked_count=0 untracked_insertions=0
        untracked_count="$(echo "$untracked_files" | wc -l | tr -d ' ')"
        while IFS= read -r f; do
            local line_count=0
            line_count="$(wc -l < "$f" 2>/dev/null | tr -d ' ')" || line_count=0
            (( untracked_insertions += line_count )) || true
        done <<< "$untracked_files"
        (( files_changed += untracked_count )) || true
        (( insertions += untracked_insertions )) || true
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
