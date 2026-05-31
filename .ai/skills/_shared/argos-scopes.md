# Argos — Conventional Commit Scopes

Use exactly one scope per commit. If a change touches multiple scopes,
split the commit.

| Scope     | Use for                                                          |
|-----------|------------------------------------------------------------------|
| `worker`  | Worker container code, phases, bash libs, worker Dockerfile      |
| `admin`   | Filament resources, panels, widgets, admin UX                    |
| `db`      | Migrations, seeders, schema changes                              |
| `queue`   | Horizon configuration, jobs, queue infrastructure                |
| `ai`      | Boost setup, skills, guidelines, hooks, CLAUDE.md sources        |
| `docs`    | Documentation in `docs/`, README, CONTRIBUTING                   |
| `dev`     | Local dev tooling (`.tools/bin/`, composer dev scripts)          |
| `release` | Release pipeline, CI, versioning, GitHub Actions                 |

## Historical scope: `retro M<n>`

Past commits use `retro M<n>` (e.g. `feat(retro M11)`, `ci(retro M15)`) for
wave-1 retrospective milestones. **Do not invent new `retro M<n>` scopes.**
The milestone numbering is closed; use a topical scope from the table above.

## When no scope fits

Omit the scope rather than invent a new one. Propose new scopes via `/retro`
so they land in this table intentionally, not inline during feature work.
