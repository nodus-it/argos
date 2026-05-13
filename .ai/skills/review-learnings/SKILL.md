---
name: review-learnings
description: Review accumulated retrospective findings in the learnings inbox and decide which to apply as guidelines, skills, architecture tests, or hooks. Use when the user wants to triage the inbox.
scope: argos
---

# Learnings Review

## 1. Read the inbox

```bash
cat .ai/learnings/inbox.md
```

If empty or missing, report: "No pending learnings to review."

The inbox is written in German — read and discuss accordingly. The user may
converse in German or English at their preference.

## 2. Process each finding

For each finding, present it with three options:

- **Apply** → integrate at the proposed location (guideline / skill /
  architecture test / hook)
- **Defer** → keep in inbox, decide later
- **Discard** → noise, not worth acting on

For "Apply":

- Draft the actual change (new lines in guideline, new skill file,
  new arch test, new hook entry) — **in English**, regardless of inbox
  language. The artifact going into the codebase is always English.
- Show the diff.
- Get explicit confirmation.
- Write the change.
- Mark the finding as processed (move to a "processed" section, do not
  delete yet — keep traceability until archive).

NEVER apply changes without per-finding confirmation. Do not batch-apply.

## 3. Archive processed findings

When all findings in the current inbox are processed, move the file to:

```
.ai/learnings/archive/YYYY-MM-DD-HHmm.md
```

(Use the date of the review, not the original finding dates.)

Creating a fresh empty inbox is not required — the next `/retro` will
create one if missing.

## 4. Summary

Report:

- N findings processed
- M applied (with one-line each), K deferred, L discarded
- Inbox archived to `<path>`
