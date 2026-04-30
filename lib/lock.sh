#!/usr/bin/env bash
# lib/lock.sh — Phase-Lock per File im Workspace.
#
# Lock liegt unter $LOCK_FILE (default /workspace/.agent/.lock) und enthält
# JSON mit pid, phase, started_at. Stale-Locks (> LOCK_STALE_SECONDS) werden
# nicht automatisch entfernt — der Benutzer muss `--force-unlock` setzen.
#
# Siehe IMPLEMENTATION.md Abschnitt 6.

# shellcheck shell=bash

LOCK_FILE="${LOCK_FILE:-/workspace/.agent/.lock}"
# Default: 4 Stunden, override fuer Tests via LOCK_STALE_SECONDS=...
LOCK_STALE_SECONDS="${LOCK_STALE_SECONDS:-14400}"

# _lock_now_epoch: Aktueller Unix-Timestamp (UTC).
_lock_now_epoch() {
    date -u +%s
}

# _lock_iso_now: Aktueller ISO8601-UTC-Timestamp.
_lock_iso_now() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

# lock_acquire: Versucht den Lock zu setzen. Schreibt JSON via .tmp+mv.
# Args: $1=phase
# Returns: 0 wenn Lock akquiriert, 6 wenn jemand anderes lockt.
lock_acquire() {
    local phase="$1"
    mkdir -p "$(dirname "$LOCK_FILE")"

    if [[ -f "$LOCK_FILE" ]]; then
        local existing_phase existing_started
        existing_phase="$(jq -r '.phase // "unknown"' "$LOCK_FILE" 2>/dev/null || echo unknown)"
        existing_started="$(jq -r '.started_at // "unknown"' "$LOCK_FILE" 2>/dev/null || echo unknown)"
        if lock_is_stale; then
            echo "Lock ist stale ($existing_phase seit $existing_started, älter als ${LOCK_STALE_SECONDS}s) — erneute Acquisition braucht --force-unlock." >&2
        else
            echo "Lock bereits gesetzt: $existing_phase seit $existing_started" >&2
        fi
        return 6
    fi

    local now_iso
    now_iso="$(_lock_iso_now)"
    jq -nc --arg pid "$$" --arg phase "$phase" --arg started_at "$now_iso" \
        '{pid: ($pid|tonumber), phase: $phase, started_at: $started_at}' \
        > "${LOCK_FILE}.tmp"
    mv "${LOCK_FILE}.tmp" "$LOCK_FILE"
}

# lock_release: Entfernt den Lock-File (idempotent).
# Returns: 0 immer.
lock_release() {
    rm -f "$LOCK_FILE"
}

# lock_force_release: Entfernt den Lock auch wenn er existiert. Fuer --force-unlock.
# Returns: 0 immer.
lock_force_release() {
    rm -f "$LOCK_FILE"
}

# lock_is_stale: Prueft ob der bestehende Lock-File aelter als LOCK_STALE_SECONDS ist.
# Returns: 0 wenn stale, 1 wenn nicht stale oder kein Lock vorhanden.
lock_is_stale() {
    [[ -f "$LOCK_FILE" ]] || return 1
    local started_at started_epoch now_epoch age
    started_at="$(jq -r '.started_at // empty' "$LOCK_FILE" 2>/dev/null)"
    [[ -n "$started_at" ]] || return 1
    started_epoch="$(date -u -d "$started_at" +%s 2>/dev/null || echo 0)"
    now_epoch="$(_lock_now_epoch)"
    age=$(( now_epoch - started_epoch ))
    (( age >= LOCK_STALE_SECONDS ))
}

# lock_info: Gibt Phase + started_at + pid des aktuellen Locks tab-separiert aus.
# Returns: 0; gibt nichts aus wenn kein Lock vorhanden.
lock_info() {
    [[ -f "$LOCK_FILE" ]] || return 0
    jq -r '"\(.phase)\t\(.started_at)\t\(.pid)"' "$LOCK_FILE" 2>/dev/null
}
