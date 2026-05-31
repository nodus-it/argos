---
name: commit
description: Create a git commit. Runs the test workflow as a pre-check, then formats the message per Conventional Commits with Argos-specific scopes.
scope: argos
---

# Commit Workflow

## 1. Pre-check — verify quality

Run the test workflow: `@.ai/skills/test/SKILL.md`

If `✅ All checks passed` was emitted earlier in this session and no code has
changed since, you may note "tests already green in this session" and proceed
without re-running. Otherwise, run the suite now.

If anything fails, STOP. Do not proceed to commit. Report the failure to the
user; offer to help fix.

## 2. Review staged changes

```bash
git diff --cached
```

If nothing is staged, ask the user what to stage. Do not stage everything
indiscriminately (`git add -A`) unless explicitly told.

## 3. Format the message

Use Conventional Commits per `@.ai/skills/_shared/commit-message-format.md`
with scopes from `@.ai/skills/_shared/argos-scopes.md`.

Commit messages are always in English.

## 4. Commit

```bash
git commit -m "<formatted message>"
```

Never use `--no-verify`. Never amend without explicit user request.

## 5. Confirm

Show the commit hash and one-line summary. Do not push automatically.
