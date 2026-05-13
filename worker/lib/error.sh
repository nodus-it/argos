#!/usr/bin/env bash
# lib/error.sh — phase exit code constants and the `die` helper.
#
# Single source of truth for the phase exit codes defined in
# WORKER-CONCEPT.md "Outputs & Artefakte". Sourced by the CLI and the
# phase scripts so call sites use names instead of magic numbers.
#
# shellcheck disable=SC2034
# (constants are read by callers, not by this file.)

# shellcheck shell=bash

EXIT_OK=0
EXIT_GENERAL=1
EXIT_PRECONDITION=2
EXIT_AUTH=3
EXIT_QUALITY_GATE=4
EXIT_NO_CHANGES=5
EXIT_LOCK=6
EXIT_USAGE_LIMIT=7
EXIT_MAX_TURNS=8

# die: exit with a code and an error message on stderr.
# Args: $1=exit_code, $2..$N=message
# Example: die "$EXIT_PRECONDITION" "task '$task_id' unknown"
die() {
    local code="$1"; shift
    if [[ $# -gt 0 ]]; then
        echo "Error: $*" >&2
    fi
    exit "$code"
}
