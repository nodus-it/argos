# Code-Struktur-Analyse Argos — Befunde & Stand

Fünf parallele Analysen + eigene Verifikation, kritisch gefiltert (Over-Engineering
wie „DemoDeployer in 10 Micro-Services" verworfen). Vier Problemklassen:
**A)** echte Duplikate, **B)** God-Classes / vermischte Logik, **C)** Logik in
Filament & Views, **D)** Folge-Aktionen synchron statt über Events.

> **Stand 2026-06-07** — Block **A** und **D komplett erledigt** (Branch
> `feat/saloon-github-pilot`, gepusht). Verbleibend: **B, C**.

---

## ✅ Erledigt (Runde 1)

| Ref | Was | Lösung | Commit |
| --- | --- | --- | --- |
| A4 | HTTP-Setup 7× dupliziert (`http()` in allen Provider-Services) | **Saloon-Rollout**: alle 4 Provider × GitService+Tracker hinter Connectoren in `app/Integrations/`; Arch-Regel bewacht die Grenze | `a9bf775`…`5373db3` |
| A1 | `parseDiffStructured` 2× + Docker-Diff-Shell-out 2× | `App\Services\Git\DiffParser` + `WorkspaceDiffService` | `5959e4f` |
| A2 | `parseRef` 4× identisch | `ParsesExternalProjectRef`-Trait | `43a3f8f` |
| A3 | Phase→Icon-Mapping 3× in Views | `App\Support\PhaseGlyph` | `0403618` |
| A5 | Cost/Token-Format 5× | `App\Support\CostFormatter` (ViewTask auf 4 Dezimal vereinheitlicht) | `ecb2996` |
| A7 | Git-Provider-Zugriff in Onboarding + RepoProfileResource | `App\Services\Git\RepositoryFetcher` | `5714c43` |
| A6 | *(angeblich URL-Parsing 5×)* | **Fehlbefund** — `repoPathFromUrl` ist schon zentralisiert, die übrigen Stellen rufen sie nur auf | — |
| D | Folge-Aktionen synchron inline statt über Events | 5 Listener in `app/Listeners/Task` an `PhaseCompleted`/`TaskCompleted`; `WorkflowService::completePhase` = reine State-Transition, `TaskService::markCompleted` = reiner DB-Write + Event (`docker volume rm` raus aus dem DB-Service) | `2f6f698` |

Außerdem teil-erledigt aus Block **C**: die Docker-Diff-Generierung verließ ViewTask/
ViewTaskDiff (→ A1) und die Git-Provider-Form-Closures verließen RepoProfileResource/
Onboarding (→ A7).

---

## B) God-Classes / vermischte Verantwortlichkeiten — offen

| # | Klasse | Problem | Trennung |
| --- | --- | --- | --- |
| B1 | `PhaseRunner.php` (~1200 Z., 34 Methoden) | mischt: Docker-Run-Orchestrierung, Env-/Credential-Aufbau (`buildCommand`), Config-Resolver (`resolveModel/AgentName/WorkerImage/MaxTurns`), Volume-I/O, `postPhaseSync` ~180 Z. mit 3 Phasen-Zweigen, Cost-Recovery, Usage-Limit-Cache | mind. abspalten: `PhaseEnvironmentBuilder`, `PhaseConfigResolver`, `WorkerVolumeReader`, je Phase ein `…PhaseSync`, `UsageLimitManager` |
| B2 | `DemoDeployer.php` (727 Z.) | Traefik-Routing (`writeTraefikRoute`, `buildAuthMiddleware`, `basicAuthUserLine`, `routeFilePath`, `traefikDir`) + Compose-Override-Bau (`buildOverrideYaml`) + Health-Probe + Concurrency-Cap + Slug/URL-Bau in einer Klasse | realistisch **3** Klassen, nicht 10: `TraefikRouter`, `DemoComposeBuilder`, `DemoDeployer` (Orchestrator) |
| B3 | `Task.php` Fat Model | Presentation-Logik im Model: `displayStatusLabel`, `displayStatusColor`, `displayBadgeStatus`, `phaseRail` (~35 Z. State-Machine), `effectiveDemoAccessMode`, `modelForPhase` | `App\Presenters\TaskPresenter` |
| B4 | `IssueCommentNotifier` / `IssueIngestService` | Orchestrierung + Markdown-Bau + Text-Capping bzw. Filter-Matching + Signatur-Hash + Task-Factory in je einer Klasse | `CommentFormatter`, `IssueFilterMatcher`, `IssueSignature` abspalten |

Zu B2: „10 Micro-Services" ist Over-Engineering — Traefik raus + Compose-Bau raus
reicht, danach ist `DemoDeployer` ein lesbarer Orchestrator.

Gegenbefund: Es gibt **keine** Git-Operationen in den IssueTracker-Klassen — die
vermutete Git/Issue-Vermischung existiert dort nicht. Die echte Vermischung saß im
HTTP-Setup (A4, erledigt) und den Orchestrierungs-Services (B4).

---

## C) Logik in Filament-Pages & Blade-Views — teilweise offen

Kein `app/View/Components/` vorhanden — alle Components sind anonyme Blade-Templates,
d.h. strukturell kein Ort für getypte Props/Computed-Logik.

**Verbleibend in Filament-Pages:**
- `ViewTask.php`: `buildThread()`/`buildPhaseItem()` (~170 Z. Workflow-Historie +
  Markdown + Status-Mapping) → `TaskThreadBuilder`. `Str::markdown()` direkt in der Page.
- `ViewTask.php` `demoAccess`-Action: Passwort-Generierung + `DemoDeployer->applyAccessMode`
  inline → dünner Service-Call.

**Verbleibend in Views (`@php`-Blöcke):**
- `view-task-thread.blade.php`: Demo-Status/Access-Badge-Mapping, Quality-Gate-URL-Bau
  mit `TaskResource::getUrl(...)` → ins ViewModel/Presenter.
- `onboarding.blade.php`: Step-State-Berechnung (done/active/reachable) + bedingtes
  Daten-Laden → in die Livewire-Page (`#[Computed]`).

---

## Empfohlene Reihenfolge (Rest)

1. **B3 (`TaskPresenter`)** — klarer Schnitt, hebt Presentation-Logik aus dem Model;
   zahlt auf C ein (View-`@php`-Status-Mappings können auf den Presenter zeigen).
2. **C-Rest** — `TaskThreadBuilder`, `demoAccess`-Service, View-`@php` ins ViewModel.
3. **B4** — `IssueCommentNotifier`/`IssueIngestService` aufteilen.
4. **B1 / B2** (`PhaseRunner` / `DemoDeployer`) — größter Aufwand, zuletzt, jede
   Extraktion mit eigenem Test.

Optional als Querschnitt: **Provider-Descriptor** („neuer Provider = ein Ort") aus der
Saloon-/Driver-Diskussion — eigenes Vorhaben.

Jeder Schritt ist für sich lauffähig und testbar — pro Extraktion erst Test (bzw.
bestehenden Filter grün), dann Schnitt.
