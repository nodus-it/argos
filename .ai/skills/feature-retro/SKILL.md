---
name: feature-retro
description: Run a retrospective on recent work — typically on the develop branch, weekly. Analyzes recent commits, current diff, and conversation patterns to suggest improvements to guidelines, skills, architecture tests, or hooks. Triggers when the user says "retro", "let's review", or invokes /retro.
scope: argos
---

# Feature Retrospective

## 1. Determine the scope

Default scope on `develop`: all commits since the last archive entry under
`.ai/learnings/archive/`, or the last 7 days if no archive exists yet.

If the user is **not** on `develop`, ask once before proceeding:
"You're not on develop. Run retro for the current branch's range, or switch
to develop first?"

Otherwise, capture:

```bash
git log <range> --format='%h %s'   # what was done
git diff <range>                    # what changed
```

Plus the conversation context since the scope started (best-effort from
the current session).

## 2. Analyze for friction patterns

Look for, in order of importance:

**A. Corrections** — places where I was told "no, do X instead". Strongest
signal for missing guideline or convention.

**B. Re-tries** — same logical task attempted multiple times. Indicates
missing context or unclear conventions.

**C. Tool failures + manual fix** — commands that failed; user fixed by hand.
Often a missing skill or wrong default.

**D. Implicit domain knowledge** — user explained something I should have
known. Strong candidate for guideline or project context.

**E. Process gaps** — repetitive prep/formatting/post-steps the user did
manually. Candidate for a new skill.

**IGNORE:**

- One-off clarifications about user intent (creative/design decisions)
- Things already covered by existing skills/guidelines (check first)
- Single anomalies without a pattern

## 3. For each finding, propose (write in GERMAN)

The inbox is the user's private notebook — write findings in **German**, even
though this skill is written in English. The user reviews them in German.

Structure per finding:

```
### [Kurzer Titel]

- **Beobachtung:** [1-2 Sätze, mit Commit-Hash oder Datei-Ref wenn möglich]
- **Häufigkeit:** [einmalig | wiederholt in Session | systemisch]
- **Empfehlung:**
  - Ebene: [Guideline | Skill | Architecture test | Hook]
  - Scope: [universal | argos]
- **Begründung:** [warum genau diese Ebene — Bezug zur existierenden Struktur]
- **Entwurf:**

  ```
  [konkreter Text-Vorschlag oder Code]
  ```
```

## 4. Write to inbox (German)

Append all findings to `.ai/learnings/inbox.md`. Create the file with a
header if missing. Group findings under a session timestamp:

```markdown
## Session 2026-05-12 14:30

### [Finding 1]
...

### [Finding 2]
...
```

NEVER apply changes directly. The inbox is the contract — user reviews and
decides via `/review-learnings`.

## 5. Close out

Report summary in English:
"N findings logged to `.ai/learnings/inbox.md` (in German). Run
`/review-learnings` when ready."
