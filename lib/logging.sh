#!/usr/bin/env bash
# lib/logging.sh — Einheitliches Logging mit Levels.
#
# Alle Log-Funktionen schreiben auf stderr, damit stdout für Result-JSON
# der Phasen frei bleibt. Bei Bedarf Färbung wenn stderr ein TTY ist.
#
# LOG_LEVEL kontrolliert was ausgegeben wird (default: info):
#   error  — nur Fehler
#   warn   — Fehler + Warnungen
#   info   — Fehler + Warnungen + Info (default)
#   debug  — alles, inkl. Debug-Spam
#
# Wird gesourced — kein eigenes `set -euo pipefail`, das überlässt
# diese Datei dem Caller.

# shellcheck shell=bash

# _log_should_emit: Prüft, ob ein gegebener Level laut LOG_LEVEL ausgegeben wird.
# Args: $1=Level-Name (error|warn|info|debug)
# Returns: 0 wenn ja, sonst 1.
_log_should_emit() {
    local level="$1"
    local current="${LOG_LEVEL:-info}"
    local -A rank=([error]=0 [warn]=1 [info]=2 [debug]=3)
    local cur_n=${rank[$current]:-2}
    local lvl_n=${rank[$level]:-2}
    (( lvl_n <= cur_n ))
}

# _log_color: Liefert ANSI-Farbcode für einen Level wenn stderr ein TTY ist.
# Args: $1=Level-Name
# Output: Color-Escape oder leer.
_log_color() {
    [[ -t 2 ]] || { echo ""; return; }
    case "$1" in
        error) echo $'\033[31m' ;;   # rot
        warn)  echo $'\033[33m' ;;   # gelb
        info)  echo $'\033[36m' ;;   # cyan
        debug) echo $'\033[90m' ;;   # grau
        *)     echo "" ;;
    esac
}

# _log: Interne Helfer-Funktion, schreibt Zeile auf stderr.
# Args: $1=Level-Name (kleinbuchstaben), $@=message tokens
_log() {
    local level="$1"; shift
    _log_should_emit "$level" || return 0

    local color reset=""
    color="$(_log_color "$level")"
    [[ -n "$color" ]] && reset=$'\033[0m'

    local upper
    upper="$(echo "$level" | tr '[:lower:]' '[:upper:]')"
    printf '%s[%s]%s %s\n' "$color" "$upper" "$reset" "$*" >&2
}

# log_debug: Debug-Output, sichtbar nur bei LOG_LEVEL=debug.
log_debug() { _log debug "$@"; }

# log_info: Info-Output, sichtbar ab LOG_LEVEL=info.
log_info()  { _log info  "$@"; }

# log_warn: Warning-Output, sichtbar ab LOG_LEVEL=warn.
log_warn()  { _log warn  "$@"; }

# log_error: Error-Output, immer sichtbar.
log_error() { _log error "$@"; }
