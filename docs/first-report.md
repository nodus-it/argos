# Code-Struktur-Analyse Argos — Befunde & Stand

Fünf parallele Analysen + eigene Verifikation, kritisch gefiltert (Over-Engineering
wie „DemoDeployer in 10 Micro-Services" verworfen). Vier Problemklassen:
**A)** echte Duplikate, **B)** God-Classes / vermischte Logik, **C)** Logik in
Filament & Views, **D)** Folge-Aktionen synchron statt über Events.

> **Stand 2026-06-07** — **alle Befunde abgearbeitet**: Block **A**, **B** (B1–B4),
> **C** und **D** erledigt (Branch `feat/saloon-github-pilot`). Es verbleibt nur
> noch optionaler Kleinkram (siehe unten).

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

## B) God-Classes / vermischte Verantwortlichkeiten — erledigt

### B1 `PhaseRunner` — von ~1207 → **405 Z.**, 4 Klassen abgespalten

| Schritt | Klasse | Inhalt | Commit |
| --- | --- | --- | --- |
| B1.1 | `WorkerVolumeReader` | Volume-I/O + Gate-Log-Lesen | `0f0513c` |
| B1.2 | `PhaseCommandBuilder` | Env-/Command-Bau + `resolve*`-Config-Resolver (`resolveModel`/`resolveAgentName` public); per `app()` aufgelöst | `8d2b331` |
| B1.3 | `PhaseResultSync` | `postPhaseSync` ~180 Z. + `extract*StreamLog`; Runner liest das `.bg.log` und reicht es an `sync()`, `postPhaseSync` bleibt dünne public Delegation (partial-mock-Seam) | `10a4f33` |
| B1.4 | `UsageLimitManager` | Cost-Recovery + `usage_limit.env`-Read + Usage-Limit-Cache; `UsageLimitBanner`/`RunPhaseJob` lesen über `current()`/`isActive()`/`retryDelaySeconds()`/`clear()` statt direkt `Cache::get` | `9461bb1` |

PhaseRunner orchestriert jetzt nur noch den Phasen-Lauf. Die partial-mock-Tests sind
pro Schnitt mitgewandert — die Test-Kopplung an die alte Struktur war der eigentliche
Aufwand (Helfer per Konstruktor-Args statt `$this->partialMock`, das den Konstruktor
überspringt).

### B2 `DemoDeployer` — von 727 → **454 Z.**, 2 Klassen abgespalten

| Klasse | Inhalt |
| --- | --- |
| `TraefikRouter` | Route-Datei schreiben/entfernen (`writeRoute`/`removeRoute`), Auth-Middleware (Session/Basic), Port-Recovery, Demo-URL-Bau |
| `DemoComposeBuilder` | Compose-Override-YAML (`buildOverrideYaml`) inkl. APP_KEY/Cookie; bekommt die Demo-URL vom `TraefikRouter` als Parameter |

`DemoDeployer` bleibt lesbarer Orchestrator (deploy/teardown/Concurrency-Cap/Health-
Probe/Contract-Lesen). „10 Micro-Services" wurde als Over-Engineering verworfen.

Gegenbefund: Es gibt **keine** Git-Operationen in den IssueTracker-Klassen — die
vermutete Git/Issue-Vermischung existiert dort nicht. Die echte Vermischung saß im
HTTP-Setup (A4) und den Orchestrierungs-Services (B4).

---

## Optionaler Rest (nicht eingeplant)

Kleiner C-Rest (geringer Wert): `onboarding.blade.php` Step-State-Berechnung
(done/active/reachable) → in die Livewire-Page (`#[Computed]`). Reine UI-Conditionals,
kein dringender Schnitt.

Optional als Querschnitt: **Provider-Descriptor** („neuer Provider = ein Ort") aus der
Saloon-/Driver-Diskussion — eigenes Vorhaben.

Jeder Schritt ist für sich lauffähig und testbar — pro Extraktion erst Test (bzw.
bestehenden Filter grün), dann Schnitt.
