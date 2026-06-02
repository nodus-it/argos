# Argos — UI Design Brief

> **How to use this file:** Drop it into [Claude Design](https://claude.ai/design)
> as context (paste the text, or attach the file + link the repo). It describes
> what Argos is, the hard technical constraints, every screen that needs a look,
> and the visual direction. Ask Claude Design to produce a **design system +
> high-fidelity mockups of the key screens** (HTML/CSS/Tailwind), which we then
> port onto the live Filament UI.

---

## 1. What Argos is

Argos is a **web-first autonomous dev agent**. A user hands it a coding task
(a plan / concept) against one of their connected Git repositories; Argos then
drives the task through three phases and opens a pull request:

1. **Concept** — turns the plan into a concrete approach, creates the feature branch.
2. **Implement** — writes the code in an isolated Docker worker.
3. **Push (PR)** — opens the pull request.

A **Respond** phase handles review feedback afterwards. The whole thing runs
asynchronously: the UI is a control room where the user watches running tasks,
reads concepts/diffs/logs, manages repos and credentials, and steers the agent.

Under the hood it's two Docker images (a **Manager** running this Laravel/Filament
app, and a **Worker** that runs Claude/Codex sessions). The user-facing surface is
**this admin panel** — that's what we're redesigning.

### Product personality

- **Audience:** developers and technical teams. Comfortable with terminals,
  diffs, logs, branches. Not a consumer app — but should feel modern and calm,
  not like a 2010-era admin dashboard.
- **Tone:** focused, trustworthy, quietly confident. It's an autonomous agent
  acting on your codebase — the UI should make its state legible at a glance
  (what's running, what's waiting, what failed) and never feel chaotic.
- **Keywords:** *control room, observability, craftsmanship, calm density.*

---

## 2. Hard technical constraints (please respect — this is what makes the output usable)

The final UI is **not** a static site. It is a **Filament v5** admin panel.
Whatever you design, we have to express it through Filament + Tailwind. So:

- **Framework:** Laravel 13 + **Filament v5** (PHP admin panel framework) +
  **Livewire v4** (server-rendered reactive components).
- **CSS:** **Tailwind CSS v4** — configured via the `@theme` directive in CSS
  (there is **no** `tailwind.config.js`). Design tokens must be expressible as
  Tailwind v4 `@theme` custom properties and standard Tailwind utility classes.
- **Theming levers we actually have:**
  - Filament panel **color palette** (`->colors([...])`) — primary + semantic
    colors (info/success/warning/danger/gray), each a 50→950 shade ramp.
  - Filament **font** (`->font('...')`), **brand logo**, **favicon**, max content width.
  - A **custom Filament theme CSS** file for deeper component overrides.
  - **Custom Blade/Livewire views** for the bespoke screens (task view, concept,
    diff, logs, onboarding) — these are free-form HTML+Tailwind and the place
    where you can be most expressive.
- **Dark mode is mandatory.** The app ships light + dark and toggles at runtime.
  Every color decision needs a light and a dark value (Tailwind `dark:` variants).
- **Please prefer standard Tailwind utility classes** in your mockups over custom
  CSS, and **call out the exact color ramp** (hex per shade) you want for primary
  and each semantic color — that maps directly onto Filament's palette.
- Icons: the app uses **Heroicons** (outline). Stick to a Heroicons-compatible
  icon language so we can reuse them 1:1.

**What we want from you is therefore:** a coherent **design system** (color ramps,
typography scale, spacing, radii, shadows, component styles, states) **plus
mockups of the key screens** — expressed in Tailwind so the handoff is mechanical.

---

## 3. Current state (what exists today — the baseline you're improving)

A single Filament panel at `/admin`, default Filament look with minimal theming:

- **Primary color:** Filament `Slate` (cool gray). Otherwise stock Filament.
- **Font:** `Instrument Sans` (body), monospace for the wordmark and logs.
- **Logo:** an "eye" mark (an allusion to *Argos*, the all-seeing giant) — an
  indigo eyelid outline + indigo iris + white pupil with a terminal cursor `>`
  inside it, next to a monospace `ARGOS` wordmark. The eye + terminal-cursor
  motif is core brand equity — **keep the concept**, feel free to refine the execution.
- It currently looks like "default Filament with a slate accent." Functional,
  but generic. We want it to feel like a deliberate, crafted product.

### Brand motif to carry forward

- **The all-seeing eye** (observability, watching your code) + **the terminal
  cursor** (it's a dev agent). Indigo is the current accent inside the logo.
  You may evolve the accent color, but the eye/terminal concept should survive.

---

## 4. Screen inventory (everything that needs a design)

Group these by priority. **Tier 1** screens are where users spend their time and
where great design matters most.

### Tier 1 — the core loop
- **Dashboard** — the landing/control room. Today: a stats row
  (Running Workers · In Progress · Waiting · Worker Updates) + a live table of
  current tasks (auto-refreshing every 5s, sorted by status priority). This is the
  "is everything okay?" glance screen — make status instantly legible.
- **Task detail view** — the richest screen. A single task with its phases and
  several sub-views (tabs/sections):
  - **Concept** — the agent's proposed approach (rich text / markdown).
  - **Diff** — a Git diff viewer (added/removed lines, per-file).
  - **Logs** — a **terminal-style** live log stream of a phase run.
  - **Respond** — an interface to send review feedback back to the agent.
  - **Quality-gate log** — pass/fail output of quality checks.
  - Plus: a live-demo card and rebuild/stop actions.
  - Needs clear **phase state** visualization (Draft → Concept → Implement →
    Push → done / failed / waiting-for-feedback) and a primary "advance" action.
- **Task list** + **Task create** form.

### Tier 2 — setup & onboarding
- **Onboarding** — a 3-step stepper (Agents → Repository → Done). First-run
  experience; should feel welcoming and guided.
- **Settings** — Claude token management + Codex auth.
- **Connected Accounts** — OAuth account management (avatars, provider chips).
- **Profile** — standard Filament edit-profile + locale selector.

### Tier 3 — configuration & infra (CRUD-heavy, can stay closer to Filament defaults)
- **Repo Profiles** (the connected repositories) — list/create/edit/view, with
  a Tasks relation manager and provider-binding relation manager.
- **Agent Credentials**, **API Clients**, **Provider Credentials**,
  **Provider OAuth Configs** — credential CRUD.
- **Worker Stacks** + **Worker Image Builds** (with a build-log view) — infra.

### Recurring UI elements (design these as reusable patterns)
- **Status badges** for task/phase state (running, waiting, success, failed,
  draft) — the single most important visual primitive; needs a clear, consistent
  color+icon language across dashboard, lists, and detail.
- **Usage banner** (top, amber) — Claude usage-limit warning.
- **Usage sidebar widget** — 5h / 7d rolling usage bars in the sidebar footer.
- **Terminal/log panel** — monospace, dark, streaming output.
- **Help hints / callouts** — info / tip / success tones.
- **Avatars with initials fallback**, source footer (version/license/links),
  feedback button in the user menu.
- Standard Filament chrome: sidebar nav (groups: Tasks · Worker · Configuration),
  topbar, tables, forms, modals, notifications.

---

## 5. Design direction we want

We like the **Claude / Anthropic aesthetic** as the north star — but adapted into
**Argos's own identity** (don't just clone Anthropic; we ship our own product).

### Mood
- Warm, calm, editorial. Generous whitespace. High legibility. Content-first.
- Density where it counts (dashboards, logs, diffs) without feeling cramped —
  *"calm density."* The control room should feel composed, not busy.
- A little craft: considered type scale, soft shadows, restrained motion
  (the codebase already has a subtle "sweep" keyframe animation for activity).

### Color
- **Light mode:** warm off-white / paper background rather than stark `#FFFFFF`
  (think warm cream/sand neutrals), dark ink text, **one confident accent**.
- **Dark mode:** deep warm-neutral surfaces (not pure black, avoid cold blue-gray),
  comfortable contrast for long log/diff reading sessions.
- **Accent:** propose an accent that fits the eye/terminal brand. The current
  indigo is a candidate; a warm coral/terracotta (Claude-flavored) is another.
  **Pick one and commit**, and give me the full 50→950 ramp in hex.
- **Semantic colors:** define clear ramps for success (PR opened / passed),
  warning (waiting / usage limit), danger (failed), info, and neutral gray.
  These drive the status badges — they must be unambiguous at a glance.

### Typography
- A clean, modern **sans** for UI/body (open to changing from Instrument Sans).
- A good **monospace** for logs, diffs, branch names, the wordmark — this is a
  dev tool, monospace is part of the identity.
- A clear type scale (display / heading / body / caption / mono) with sizes,
  weights, and line-heights specified.

### Components & states
- Cards, tables, badges, buttons (primary/secondary/ghost/danger), inputs,
  tabs, steppers, modals, toasts, empty states, loading/skeleton states.
- **Status badges** specified explicitly for every task/phase state.
- A **terminal/log** treatment (the hero moment for a dev agent).
- A **diff** treatment (added/removed/context lines, file headers).
- Hover / focus / active / disabled states for interactive elements
  (focus-visible rings matter — keyboard users).

### Accessibility
- WCAG AA contrast in both themes. Don't rely on color alone for status — pair
  every status color with an icon and/or label.

---

## 6. What I'd love you to produce (deliverables)

1. **A design-system spec** I can hand to engineering:
   - Color ramps (primary + each semantic + neutral), **hex per 50→950 shade**,
     light and dark.
   - Typography scale (families, sizes, weights, line-heights).
   - Spacing scale, border-radii, shadow/elevation tokens.
   - Component styles with all states, expressed in **Tailwind v4 utility classes**.
2. **High-fidelity mockups** (HTML/CSS/Tailwind, light + dark) of at least:
   - Dashboard (control room)
   - Task detail view incl. the **logs (terminal)** and **diff** sub-views
   - The **status-badge** system shown across states
   - Onboarding stepper
   - One representative CRUD/list + form screen
3. A refined **logo / brand mark** exploration that keeps the **eye + terminal
   cursor** concept, plus a favicon.
4. Notes on **motion** (where subtle animation helps signal "agent is working").

### Output format that helps us most
- Give the tokens as something I can paste into Tailwind v4 `@theme` and into
  Filament's color config (a 50→950 hex ramp per color).
- Keep mockups in standard Tailwind utility classes (avoid heavy custom CSS) so
  the port to Filament/Livewire views is mechanical.

---

## 7. Things to avoid

- Don't redesign it into a generic SaaS marketing page — this is a working tool.
- Don't lose information density on the dashboard/logs/diff in the name of "clean."
- Don't rely on color alone for state. Don't drop dark mode.
- Don't introduce a JS framework or component lib that isn't Tailwind/Filament/
  Livewire-compatible — we can't ship React components here.
- Don't copy Anthropic's proprietary logo/fonts; take inspiration, ship Argos's own.

---

## 8. Quick reference — current tokens (the starting point)

| Token | Current value |
| --- | --- |
| Primary color | Filament `Slate` ramp |
| Body font | `Instrument Sans` |
| Mono font | `ui-monospace, 'Cascadia Code', 'Source Code Pro', Menlo, Consolas, monospace` |
| Logo accent | Indigo (`indigo-500/600`, cursor `#4338ca`) |
| Wordmark | `ARGOS`, monospace, 700 weight, letter-spacing 3.5 |
| Dark mode | Enabled, runtime toggle |
| Layout width | Full width |
| Icons | Heroicons (outline) |
| Nav groups | Tasks · Worker · Configuration |
