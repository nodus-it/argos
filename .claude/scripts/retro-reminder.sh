#!/bin/bash
# Argos — remind to run /retro at most once per week.
# Only active on the configured branch (develop).

set -e
cd "$(dirname "$0")/../.." || exit 0

RETRO_BRANCH="develop"           # hard-coded by design — change here if needed
STATE_FILE=".ai/learnings/.last-reminder"
ARCHIVE_DIR=".ai/learnings/archive"
WEEK_SECONDS=$((7 * 86400))

mkdir -p "$(dirname "$STATE_FILE")"

# Only remind on the configured branch
BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")
[ "$BRANCH" = "$RETRO_BRANCH" ] || exit 0

# When was the last retro? (most recent file in archive)
LAST_RETRO=0
if [ -d "$ARCHIVE_DIR" ]; then
  NEWEST=$(ls -t "$ARCHIVE_DIR" 2>/dev/null | head -1)
  if [ -n "$NEWEST" ]; then
    LAST_RETRO=$(stat -c %Y "$ARCHIVE_DIR/$NEWEST" 2>/dev/null \
                || stat -f %m "$ARCHIVE_DIR/$NEWEST" 2>/dev/null \
                || echo 0)
  fi
fi

# When did we last remind?
LAST_REMINDER=$(cat "$STATE_FILE" 2>/dev/null || echo 0)

NOW=$(date +%s)
DAYS_SINCE_RETRO=$(( (NOW - LAST_RETRO) / 86400 ))
SECONDS_SINCE_REMINDER=$(( NOW - LAST_REMINDER ))

# Due?
if [ "$LAST_RETRO" -gt 0 ]; then
  DUE=$([ "$DAYS_SINCE_RETRO" -ge 7 ] && echo yes || echo no)
else
  DUE=yes
fi

# Throttled?
THROTTLED=$([ "$SECONDS_SINCE_REMINDER" -lt "$WEEK_SECONDS" ] && echo yes || echo no)

if [ "$DUE" = "yes" ] && [ "$THROTTLED" = "no" ]; then
  if [ "$LAST_RETRO" -gt 0 ]; then
    echo "💡 Last retrospective: $DAYS_SINCE_RETRO days ago. Worth running /retro?"
  else
    echo "💡 No retrospective recorded yet. Worth running /retro when you have a moment?"
  fi
  echo "$NOW" > "$STATE_FILE"
fi

exit 0
