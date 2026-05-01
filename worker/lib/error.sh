#!/usr/bin/env bash
# lib/error.sh — Exit-Code-Konstanten und die-Helper.
#
# Zentrale Quelle für die in WORKER-CONCEPT.md "Outputs & Artefakte"
# definierten Phase-Exit-Codes. CLI und Phase-Skripte sourcen diese
# Datei und nutzen die Konstanten statt magischer Zahlen.
#
# shellcheck disable=SC2034
# (Konstanten werden von Callern genutzt, nicht in dieser Datei.)

# shellcheck shell=bash

# Phase-Exit-Codes (siehe WORKER-CONCEPT.md, Abschnitt "Outputs & Artefakte")
EXIT_OK=0
EXIT_GENERAL=1
EXIT_PRECONDITION=2
EXIT_AUTH=3
EXIT_QUALITY_GATE=4
EXIT_NO_CHANGES=5
EXIT_LOCK=6

# die: Beendet das Skript mit Exit-Code und Fehlermeldung auf stderr.
# Args: $1=exit_code, $2..$N=Fehlermeldung (frei zusammengesetzt)
# Beispiel: die "$EXIT_PRECONDITION" "Task '$task_id' nicht bekannt"
die() {
    local code="$1"; shift
    if [[ $# -gt 0 ]]; then
        echo "Error: $*" >&2
    fi
    exit "$code"
}
