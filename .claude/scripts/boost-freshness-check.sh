#!/bin/bash
# SessionStart hook: regenerate CLAUDE.md if its sources are newer.
# Output is shown to Claude as context — keep messages brief and factual.

set -e
cd "$(dirname "$0")/../.." || exit 0

CLAUDE_MD="CLAUDE.md"

# Case 1: CLAUDE.md doesn't exist (fresh clone, never installed)
if [ ! -f "$CLAUDE_MD" ]; then
  if php artisan boost:install --no-interaction --quiet 2>/dev/null; then
    echo "📝 CLAUDE.md generated (was missing)."
  fi
  exit 0
fi

# Case 2: A source file is newer than CLAUDE.md.
# Use `|| true` so a missing .ai/ on early setup doesn't trip `set -e`.
NEWEST_SOURCE=$(find .ai/ boost.json composer.lock 2>/dev/null \
  -type f \
  -newer "$CLAUDE_MD" \
  -print -quit || true)

if [ -n "$NEWEST_SOURCE" ]; then
  if php artisan boost:install --no-interaction --quiet 2>/dev/null; then
    echo "📝 CLAUDE.md regenerated ($NEWEST_SOURCE was newer)."
  fi
fi

exit 0
