# Code-Struktur-Analyse Argos — Befunde & Stand

Fünf parallele Analysen + eigene Verifikation, kritisch gefiltert (Over-Engineering
wie „DemoDeployer in 10 Micro-Services" verworfen). Vier Problemklassen:
**A)** echte Duplikate, **B)** God-Classes / vermischte Logik, **C)** Logik in
Filament & Views, **D)** Folge-Aktionen synchron statt über Events.

> **Stand 2026-06-07** — Block **A**, **D**, **B3**, **B4** und **C** erledigt
> (Branch `feat/saloon-github-pilot`). Verbleibend: **B1, B2**.

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
| B3 | Presentation-Logik im Fat Model `Task.php` | `App\Presenters\TaskPresenter` (statusLabel/Color, badgeStatus, phaseRail + Helfer), erreichbar via `Task::presenter()`; Domain-Methoden bleiben im Model | `6637dce` |
| C | Logik in Filament-Pages & Views | `TaskThreadBuilder` (Thread-Aufbau aus ViewTask), `DemoAccessConfigurator` (Passwort/Apply aus der Action), `App\Support\BadgeClass` + `DemoAccessMode::icon()` + Quality-Gate-`lastKeys` aus `view-task-thread` | `a32b480`, `ae92547`, `99d0969` |
| B4 | `IssueCommentNotifier` / `IssueIngestService` mischen Orchestrierung + Markdown/Filter/Hash | `CommentFormatter` (Markdown+Cap), `IssueFilterMatcher`, `IssueSignature` abgespalten; öffentliche APIs unverändert | `82082e4` |

Außerdem teil-erledigt aus Block **C**: die Docker-Diff-Generierung verließ ViewTask/
ViewTaskDiff (→ A1) und die Git-Provider-Form-Closures verließen RepoProfileResource/
Onboarding (→ A7).

---

## B) God-Classes / vermischte Verantwortlichkeiten — offen

| # | Klasse | Problem | Trennung |
| --- | --- | --- | --- |
| B1 | `PhaseRunner.php` (~1200 Z., 34 Methoden) | mischt: Docker-Run-Orchestrierung, Env-/Credential-Aufbau (`buildCommand`), Config-Resolver (`resolveModel/AgentName/WorkerImage/MaxTurns`), Volume-I/O, `postPhaseSync` ~180 Z. mit 3 Phasen-Zweigen, Cost-Recovery, Usage-Limit-Cache | mind. abspalten: `PhaseEnvironmentBuilder`, `PhaseConfigResolver`, `WorkerVolumeReader`, je Phase ein `…PhaseSync`, `UsageLimitManager` |
| B2 | `DemoDeployer.php` (727 Z.) | Traefik-Routing (`writeTraefikRoute`, `buildAuthMiddleware`, `basicAuthUserLine`, `routeFilePath`, `traefikDir`) + Compose-Override-Bau (`buildOverrideYaml`) + Health-Probe + Concurrency-Cap + Slug/URL-Bau in einer Klasse | realistisch **3** Klassen, nicht 10: `TraefikRouter`, `DemoComposeBuilder`, `DemoDeployer` (Orchestrator) |

Zu B2: „10 Micro-Services" ist Over-Engineering — Traefik raus + Compose-Bau raus
reicht, danach ist `DemoDeployer` ein lesbarer Orchestrator.

Gegenbefund: Es gibt **keine** Git-Operationen in den IssueTracker-Klassen — die
vermutete Git/Issue-Vermischung existiert dort nicht. Die echte Vermischung saß im
HTTP-Setup (A4, erledigt) und den Orchestrierungs-Services (B4).

---

## Empfohlene Reihenfolge (Rest)

1. **B1 (`PhaseRunner`)** — die echte God-Class (~1200 Z., 7 Verantwortlichkeiten),
   schrittweise. **In Arbeit:**
   - ✅ B1.1 `WorkerVolumeReader` (Volume-I/O + Gate-Log-Lesen) — `0f0513c`, jetzt 1047 Z.
   - ✅ B1.2 `PhaseCommandBuilder` (Env-/Command-Bau + `resolve*`-Config-Resolver) —
     jetzt 753 Z.; per `app()` aufgelöst (nicht Konstruktor-injiziert), konsistent
     mit dem bereits in `runBlocking` genutzten `app(WorkflowService::class)`-Muster,
     hält alle `partialMock`-Tests stabil. `resolveModel`/`resolveAgentName` public.
   - ✅ B1.3 `PhaseResultSync` (`postPhaseSync` ~180 Z. + `extract*StreamLog`-Helfer) —
     jetzt 541 Z. Runner liest das host-seitige `.bg.log` (besitzt die Log-Datei)
     und reicht es an `sync()`; `postPhaseSync` bleibt als dünne, public Delegation
     (partial-mock-Seam für die Tests).
   - ⏳ B1.4 Usage/Cost (`recoverUsageFromVolume`, Usage-Limit-Cache) → `UsageLimitManager`
   Jede Extraktion einzeln mit Test (die partial-mock-Tests müssen pro Schnitt
   mitwandern — die Test-Kopplung an die alte Struktur ist der eigentliche Aufwand).
2. **B2 (`DemoDeployer`)** — `TraefikRouter` + `DemoComposeBuilder` raus, Rest bleibt
   lesbarer Orchestrator. Gut durch `DemoDeployerTest` abgedeckt.

Kleiner C-Rest (optional, geringer Wert): `onboarding.blade.php` Step-State-
Berechnung (done/active/reachable) → in die Livewire-Page (`#[Computed]`). Reine
UI-Conditionals, kein dringender Schnitt.

Optional als Querschnitt: **Provider-Descriptor** („neuer Provider = ein Ort") aus der
Saloon-/Driver-Diskussion — eigenes Vorhaben.

Jeder Schritt ist für sich lauffähig und testbar — pro Extraktion erst Test (bzw.
bestehenden Filter grün), dann Schnitt.
