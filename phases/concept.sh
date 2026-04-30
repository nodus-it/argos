#!/usr/bin/env bash
# phases/concept.sh — Phase concept: Aufgabe analysieren, Plan formulieren.
#
# Wird vom Worker-Entrypoint gesourced. Erwartet:
#   - lib/{logging,error,result,state,prompts}.sh bereits gesourced
#   - env: TASK_ID, REPO_URL, REPO_TOKEN, BASE_BRANCH, ITERATION,
#          PHASE_FLAGS (JSON), CLAUDE_CODE_OAUTH_TOKEN
#   - /workspace ist das Task-Volume (gemountet)
#   - /workspace/.agent/description.md ist als read-only Bind-Mount
#     vom Host vorhanden (siehe lib/docker.sh)
#
# Siehe IMPLEMENTATION.md Abschnitt 1.1 fuer den Claude-Aufruf.

# shellcheck shell=bash

# phase_concept_help: Kurzbeschreibung der Phase fuer `agent help concept`.
phase_concept_help() {
    echo "Konzept-Phase: Aufgabe analysieren und Plan formulieren."
}

# phase_concept_preconditions: Prueft Vorbedingungen.
# Returns: 0 wenn OK, sonst Exit-Code mit Fehlermeldung auf stderr.
phase_concept_preconditions() {
    if [[ ! -f /run/agent/description.md ]]; then
        echo "concept: /run/agent/description.md fehlt — bitte 'agent task new' wiederholen oder description.md unter ~/.agent/tasks/<id>/ anlegen." >&2
        return 2
    fi
    if [[ -z "${REPO_URL:-}" || -z "${REPO_TOKEN:-}" || -z "${BASE_BRANCH:-}" ]]; then
        echo "concept: REPO_URL/REPO_TOKEN/BASE_BRANCH muessen gesetzt sein." >&2
        return 2
    fi
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "concept: CLAUDE_CODE_OAUTH_TOKEN fehlt." >&2
        return 3
    fi
    return 0
}

# _concept_initial_clone: Klont das Repo ins Volume und legt feature_branch an.
# Returns: 0 bei Erfolg, sonst Fehler.
_concept_initial_clone() {
    set +x
    local auth_url
    auth_url="$(git_auth_inject_token "$REPO_URL" "$REPO_TOKEN")"

    local now_epoch feature_branch
    now_epoch="$(date -u +%s)"
    feature_branch="ai/${TASK_ID}-${now_epoch}"

    # `git clone` weigert sich, in ein nicht-leeres /workspace zu clonen
    # (`/workspace/.agent/` existiert schon). Stattdessen: init + fetch + checkout.
    cd /workspace || return 1
    if ! git init --quiet --initial-branch="$BASE_BRANCH" 2>/workspace/.agent/logs/clone.err; then
        echo "concept: git init failed (siehe logs/clone.err)" >&2
        return 1
    fi
    # /workspace/.agent/ enthaelt unseren State und darf von 'git clean -fd' in
    # spaeteren Phasen NICHT gelöscht werden. .git/info/exclude markiert das
    # Verzeichnis lokal als ignored (kein Touch im fake-remote/Repo).
    mkdir -p /workspace/.git/info
    grep -qxF '.agent/' /workspace/.git/info/exclude 2>/dev/null \
        || echo '.agent/' >> /workspace/.git/info/exclude
    git remote add origin "$auth_url" 2>/dev/null || git remote set-url origin "$auth_url"
    if ! git fetch --quiet --depth=1 origin "$BASE_BRANCH" 2>>/workspace/.agent/logs/clone.err; then
        echo "concept: git fetch failed (siehe logs/clone.err)" >&2
        git remote set-url origin "$REPO_URL"
        return 1
    fi
    git checkout -B "$feature_branch" "origin/$BASE_BRANCH"
    # Originellen URL ohne Token zurueckschreiben damit credentials nicht
    # im Workspace persistieren.
    git remote set-url origin "$REPO_URL"

    state_set_feature_branch "$feature_branch"
}

# _concept_archive_to_history: Verschiebt vorhandene Konzept-/Notes-Datei nach concept.history/.
# Args: $1=mode ("move"|"copy")
# Output: anzahl der jetzt vorhandenen history-Files.
_concept_archive_to_history() {
    local mode="$1"
    local concept_file=/workspace/.agent/concept.md
    local notes_file=/workspace/.agent/concept.notes.md
    local hist_dir=/workspace/.agent/concept.history
    mkdir -p "$hist_dir"
    local ts
    ts="$(date -u +%Y%m%dT%H%M%S)"

    if [[ -f "$concept_file" ]]; then
        if [[ "$mode" == "move" ]]; then
            mv "$concept_file" "$hist_dir/concept.${ts}.md"
        else
            cp "$concept_file" "$hist_dir/concept.${ts}.md"
        fi
    fi
    if [[ -f "$notes_file" ]]; then
        # Notes werden immer move'd — sie sind one-shot.
        mv "$notes_file" "$hist_dir/concept.notes.${ts}.md"
    fi

    find "$hist_dir" -maxdepth 1 -type f -name 'concept.*.md' 2>/dev/null | wc -l
}

# _concept_build_user_prompt: Erzeugt User-Prompt-Markdown auf stdout.
# Args: $1=fresh ("true"|"false"), $2=has_existing ("true"|"false")
_concept_build_user_prompt() {
    local fresh="$1" has_existing="$2"
    local description_file=/run/agent/description.md
    local concept_file=/workspace/.agent/concept.md
    local notes_file=/workspace/.agent/concept.notes.md

    {
        printf '# Konzept-Aufgabe\n\n'
        printf '## Aufgabenbeschreibung\n\n'
        cat "$description_file"
        printf '\n'

        if [[ "$fresh" == "false" && "$has_existing" == "true" ]]; then
            printf '\n## Vorheriges Konzept (zur Verfeinerung)\n\n'
            cat "$concept_file"
            printf '\n'
        fi

        if [[ -f "$notes_file" ]]; then
            printf '\n## Anmerkungen des Users (concept.notes.md)\n\n'
            cat "$notes_file"
            printf '\n'
        fi

        printf '\n## Erwartung\n\n'
        printf 'Antworte direkt mit dem Konzept-Markdown gemaess System-Prompt-Format. '
        printf 'KEINE Datei schreiben — der Worker uebernimmt das.\n'
    }
}

# phase_concept_run: Hauptlogik der Phase.
# Returns: Exit-Code (0 erfolg, 1 general, 2 precondition, 3 auth).
phase_concept_run() {
    cd /workspace 2>/dev/null || {
        echo "concept: /workspace not mounted" >&2
        return 1
    }

    mkdir -p /workspace/.agent/logs

    local fresh
    fresh="$(echo "${PHASE_FLAGS:-}" | jq -r '.fresh // false' 2>/dev/null || echo false)"

    # Erstmaliges Setup: Repo klonen wenn noch nicht da
    if [[ ! -d /workspace/.git ]]; then
        log_info "concept: kloning $REPO_URL nach /workspace"
        _concept_initial_clone || return 1
    fi
    cd /workspace || return 1

    # History-Archivierung: bei --fresh move'n, sonst nur copy
    local concept_file=/workspace/.agent/concept.md
    local has_existing=false
    [[ -f "$concept_file" ]] && has_existing=true

    local history_count
    if [[ "$fresh" == "true" && "$has_existing" == "true" ]]; then
        history_count="$(_concept_archive_to_history move)"
        has_existing=false
    elif [[ "$has_existing" == "true" ]]; then
        history_count="$(_concept_archive_to_history copy)"
    else
        history_count=0
        # Notes ohne Konzept koennen vorhanden sein — auch archivieren wenn ja
        if [[ -f /workspace/.agent/concept.notes.md ]]; then
            history_count="$(_concept_archive_to_history copy)"
        fi
    fi

    # System-Prompt mergen
    local sysprompt
    sysprompt="$(build_system_prompt concept)" || return 1

    # User-Prompt rendern
    local user_prompt_path
    user_prompt_path="$(_concept_build_user_prompt "$fresh" "$has_existing" | render_user_prompt concept user-prompt)"

    # Claude aufrufen
    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    local output_json="/workspace/.agent/logs/concept.${ITERATION}.json"
    local sysprompt_content
    sysprompt_content="$(cat "$sysprompt")"

    log_info "concept: rufe claude (max-turns 15) auf — output nach $output_json"
    if ! claude -p \
            --append-system-prompt "$sysprompt_content" \
            --output-format json \
            --max-turns 15 \
            --permission-mode bypassPermissions \
            < "$user_prompt_path" \
            > "$output_json"; then
        echo "concept: claude call failed (exit non-zero)" >&2
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$output_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$output_json" 2>/dev/null)"
        echo "concept: claude returned is_error=true: $err_msg" >&2
        return 3
    fi

    # Konzept-Text extrahieren und in concept.md schreiben
    local concept_text
    concept_text="$(jq -r '.result' "$output_json")"
    if [[ -z "$concept_text" || "$concept_text" == "null" ]]; then
        echo "concept: claude returned empty .result" >&2
        return 1
    fi
    printf '%s\n' "$concept_text" > "${concept_file}.tmp"
    mv "${concept_file}.tmp" "$concept_file"

    # Notes nach Verarbeitung in history (bereits oben passiert wenn vorhanden — nochmal sicherstellen)
    if [[ -f /workspace/.agent/concept.notes.md ]]; then
        _concept_archive_to_history move >/dev/null
    fi

    # Result-JSON emittieren
    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))
    local session_id cost
    session_id="$(jq -r '.session_id // ""' "$output_json")"
    cost="$(jq -r '.total_cost_usd // 0' "$output_json")"

    result_emit \
        phase concept \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code 0 \
        concept_path "$concept_file" \
        --int concept_history_count "$history_count" \
        claude_session_id "$session_id" \
        --raw claude_total_cost_usd "$cost"

    return 0
}
