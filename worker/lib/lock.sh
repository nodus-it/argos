#!/usr/bin/env bash
# lib/lock.sh — file-based phase lock inside the workspace.
#
# Lock lives at $LOCK_FILE (default /workspace/.agent/.lock) and contains
# JSON with pid, phase, started_at. Stale locks (> LOCK_STALE_SECONDS) are
# *not* removed automatically — the user must pass `--force-unlock`.

# shellcheck shell=bash

LOCK_FILE="${LOCK_FILE:-/workspace/.agent/.lock}"
# Default 4 hours; tests override via LOCK_STALE_SECONDS=...
LOCK_STALE_SECONDS="${LOCK_STALE_SECONDS:-14400}"

# _lock_now_epoch: current Unix timestamp (UTC).
_lock_now_epoch() {
    date -u +%s
}

# _lock_iso_now: current ISO8601 UTC timestamp.
_lock_iso_now() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

# lock_acquire: try to take the lock. Writes JSON via .tmp+mv.
# Args: $1=phase
# Returns: 0 if acquired, 6 if someone else already holds the lock.
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

# lock_release: remove the lock file (idempotent).
lock_release() {
    rm -f "$LOCK_FILE"
}

# lock_force_release: remove the lock even if it exists. Used by --force-unlock.
lock_force_release() {
    rm -f "$LOCK_FILE"
}

# lock_is_stale: true if the existing lock is older than LOCK_STALE_SECONDS.
# Returns: 0 if stale, 1 if fresh or no lock present.
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

# lock_info: print phase + started_at + pid of the current lock, tab-separated.
# Prints nothing if no lock is present.
lock_info() {
    [[ -f "$LOCK_FILE" ]] || return 0
    jq -r '"\(.phase)\t\(.started_at)\t\(.pid)"' "$LOCK_FILE" 2>/dev/null
}
