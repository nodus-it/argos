#!/usr/bin/env bash
# lib/credentials.sh — Tokens und Repo-Credentials auf dem Host.
#
# Speichert sensible Daten unter $AGENT_HOME (default ~/.agent) mit Mode 600.
# Niemals loggen, niemals im Image, niemals im Volume — siehe
# WORKER-CONCEPT.md "Sicherheits-Modell".
#
# Layout auf dem Host:
#   $AGENT_HOME/claude_oauth_token              (mode 600)
#   $AGENT_HOME/tasks/<task-id>/credentials.env (mode 600)
#       Inhalt: REPO_URL=, REPO_TOKEN=, BASE_BRANCH=

# shellcheck shell=bash

AGENT_HOME="${AGENT_HOME:-$HOME/.agent}"
CLAUDE_TOKEN_FILE="${CLAUDE_TOKEN_FILE:-$AGENT_HOME/claude_oauth_token}"

# _credentials_atomic_write: Schreibt Inhalt von stdin atomar nach $1 mit Mode 600.
# Args: $1=zielpfad
# Returns: 0 bei Erfolg.
_credentials_atomic_write() {
    local target="$1"
    mkdir -p "$(dirname "$target")"
    local tmp
    tmp="$(mktemp "${target}.XXXXXX")"
    chmod 600 "$tmp"
    cat > "$tmp"
    mv "$tmp" "$target"
    chmod 600 "$target"
}

# credentials_save_claude_token: Persistiert OAuth-Token von stdin.
# Stdin: Token-String (z.B. sk-ant-oat01-...)
# Returns: 0 bei Erfolg.
credentials_save_claude_token() {
    local token
    token="$(cat)"
    if [[ -z "$token" ]]; then
        echo "credentials_save_claude_token: empty token refused" >&2
        return 1
    fi
    printf '%s\n' "$token" | _credentials_atomic_write "$CLAUDE_TOKEN_FILE"
}

# credentials_load_claude_token: Gibt den gespeicherten Token auf stdout aus.
# Returns: 0 wenn Token vorhanden, 1 wenn nicht.
credentials_load_claude_token() {
    if [[ ! -f "$CLAUDE_TOKEN_FILE" ]]; then
        echo "credentials_load_claude_token: $CLAUDE_TOKEN_FILE not found" >&2
        return 1
    fi
    head -n1 "$CLAUDE_TOKEN_FILE"
}

# credentials_has_claude_token: Prueft ob Token-File existiert und nicht-leer ist.
# Returns: 0 wenn vorhanden, 1 wenn nicht.
credentials_has_claude_token() {
    [[ -s "$CLAUDE_TOKEN_FILE" ]]
}

# _credentials_task_path: Pfad zum Task-Verzeichnis auf dem Host.
# Args: $1=task_id
# Output: Pfad-String.
_credentials_task_path() {
    echo "$AGENT_HOME/tasks/$1"
}

# credentials_save_task: Schreibt credentials.env fuer einen Task.
# Args: $1=task_id, $2=repo_url, $3=repo_token, $4=base_branch
# Returns: 0 bei Erfolg.
credentials_save_task() {
    local task_id="$1"
    local repo_url="$2"
    local repo_token="$3"
    local base_branch="$4"
    local dir
    dir="$(_credentials_task_path "$task_id")"
    mkdir -p "$dir"
    chmod 700 "$dir"
    {
        printf 'REPO_URL=%q\n' "$repo_url"
        printf 'REPO_TOKEN=%q\n' "$repo_token"
        printf 'BASE_BRANCH=%q\n' "$base_branch"
    } | _credentials_atomic_write "$dir/credentials.env"
}

# credentials_load_task: Sourced credentials.env eines Tasks in die aktuelle Shell.
# Args: $1=task_id
# Side effect: setzt REPO_URL, REPO_TOKEN, BASE_BRANCH.
# Returns: 0 wenn geladen, 1 wenn Datei fehlt.
credentials_load_task() {
    local task_id="$1"
    local file
    file="$(_credentials_task_path "$task_id")/credentials.env"
    if [[ ! -f "$file" ]]; then
        echo "credentials_load_task: $file not found" >&2
        return 1
    fi
    # shellcheck disable=SC1090
    source "$file"
}

# credentials_delete_task: Loescht das Task-Verzeichnis komplett.
# Args: $1=task_id
# Returns: 0 immer (idempotent).
credentials_delete_task() {
    local task_id="$1"
    rm -rf "$(_credentials_task_path "$task_id")"
}

# credentials_task_exists: Prueft ob Task-Eintrag existiert.
# Args: $1=task_id
# Returns: 0 wenn ja, 1 sonst.
credentials_task_exists() {
    local task_id="$1"
    [[ -f "$(_credentials_task_path "$task_id")/credentials.env" ]]
}

# git_auth_inject_token: Baut HTTPS-URL mit oauth2-User und Token-Password.
# Args: $1=repo_url, $2=token
# Output: URL mit eingebautem Token auf stdout (oder unveraenderte URL fuer
# nicht-https Schemes wie ssh/file/git).
# Hinweis: niemals loggen — der Output enthaelt das Secret.
git_auth_inject_token() {
    local url="$1" token="$2"
    if [[ "$url" =~ ^https?:// ]]; then
        printf '%s' "$url" | sed -E "s|^(https?://)|\1oauth2:${token}@|"
    else
        printf '%s' "$url"
    fi
}
