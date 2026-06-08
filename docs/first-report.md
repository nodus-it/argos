# Code-Struktur-Analyse Argos — Befunde & Stand

Fünf parallele Analysen + eigene Verifikation, kritisch gefiltert (Over-Engineering
wie „DemoDeployer in 10 Micro-Services" verworfen). Vier Problemklassen:
**A)** echte Duplikate, **B)** God-Classes / vermischte Logik, **C)** Logik in
Filament & Views, **D)** Folge-Aktionen synchron statt über Events.

> **Stand 2026-06-07** — **alle Befunde abgearbeitet**: Block **A**, **B** (B1–B4),
> **C** und **D** erledigt (Branch `feat/saloon-github-pilot`). Es verbleibt nur
> noch optionaler Kleinkram (siehe unten).
>
> **Nachtrag 2026-06-08** — A4-Nachprüfung ergab, dass der Saloon-Rollout nur
> Git-Provider + Issue-Tracker abdeckte; 4 Raw-`Http::`-Calls (OAuth-Refresh,
> Linear-OAuth-Callback, Anthropic Token-Validierung + Usage) liefen noch an
> Saloon vorbei. Alle vier sind jetzt auf Connectoren in `app/Integrations/`
> umgestellt; ein zweiter Arch-Test (`no raw http client outside integrations`)
> verbietet `Http`-Facade/Guzzle außerhalb `app/Integrations`. Socialite bleibt
> die bewusste Ausnahme für den OAuth-**Login** (eigene Abstraktion).

---

## ✅ Erledigt (Runde 1)

| Ref | Was | Lösung | Commit |
| --- | --- | --- | --- |
| A4 | HTTP-Setup 7× dupliziert (`http()` in allen Provider-Services) | **Saloon-Rollout**: alle 4 Provider × GitService+Tracker hinter Connectoren in `app/Integrations/`; Arch-Regel bewacht die Grenze. **Nachtrag 06-08**: restliche 4 Raw-`Http::`-Calls (TokenRefresher, Linear-OAuth-Callback, Anthropic Validate+Usage) → `Anthropic`-/`OAuth`-Connector; zweiter Arch-Test verbietet Raw-HTTP außerhalb `app/Integrations` | `a9bf775`…`5373db3` |
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

## Optionaler Rest

> **Stand 2026-06-08** — O1 und O3 erledigt; O2 auf die konkreten Duplikate
> reduziert (siehe unten). Der volle Provider-Descriptor bleibt bewusst
> aufgeschoben, bis ein echter neuer Provider ihn validiert.

| # | Was | Wo | Status |
| --- | --- | --- | --- |
| O1 | Onboarding Step-State (`done`/`active`/`reachable`) aus dem Blade in die Page ziehen | `app/Filament/Admin/Pages/Onboarding.php` + `resources/views/filament/admin/pages/onboarding.blade.php` | ✅ **erledigt** — `Onboarding::steps()` liefert die Step-Deskriptoren, das Blade iteriert nur noch (kein inline-`@php`-Branching). Konvention der Datei (plain public method, kein `#[Computed]`) gefolgt. Tests in `OnboardingPageTest`. |
| O2 | Provider-Descriptor („neuer Provider = ein Ort") | `app/Services/GitProvider/` + `app/Services/IssueTracker/` u.v.m. | ⚠️ **teilweise** — der **volle** Descriptor (Enums verschmelzen, Factories generieren, ~15–20 Stellen) bleibt aufgeschoben: spekulative Abstraktion ohne konkreten neuen Provider als Validierung. **Erledigt** wurden die konkreten Duplikate darin: Clone-URL-Bau (`repoUrl`-`match`) aus `Onboarding` **und** `RepoProfileResource` → `App\Support\RepoUrlBuilder`; GitLab-Default als `RepoUrlBuilder::DEFAULT_GITLAB_INSTANCE`. |
| O3 | `CredentialStore` als tote Konstruktor-Dependency in `PhaseRunner` | `app/Services/Workflow/PhaseRunner.php` | ✅ **erledigt** — Parameter entfernt. Der Claude-Token-Pfad läuft worker-seitig über `ClaudeCodeRunner` (`app(CredentialStore::class)`), nicht über PhaseRunner. Die zwei Mockery-Test-Helfer (`FakeWorkerProcess`, `FeedbackWorkflowTest`) wurden mitgezogen. |

**Bleibt offen** (bewusst, kein Blocker): der volle Provider-Descriptor (O2) — die
Fundkarte der ~15–20 betroffenen Stellen liegt vor, Umsetzung erst mit einem
konkreten neuen Provider (z.B. Gitea/Forgejo).

---

## Vor dem Merge: vollständiger Testlauf nötig

PHP-Ebene grün:

- ✅ `vendor/bin/pest` — volle Suite **1269 Tests / 2886 Assertions / 0 Fehler**
- ✅ `vendor/bin/phpstan analyse` — **0 errors**
- ✅ `vendor/bin/pint` — clean

1. ✅ **Browser-E2E (Playwright)** gegen den Compose-Stack — `composer test:browser`
   (gemockt, `ARGOS_E2E_FAKE=1`). **Stand 2026-06-08: 7 passed, 1 skipped** (Real-Flow).
   Deckt Login → Onboarding → Projekt → Task → Concept/Implement über die 4-Run-Matrix
   (GitHub/Claude/OAuth · GitLab/Codex/PAT · Bitbucket/Claude/PAT · GitLab-self-hosted/
   Codex/OAuth) ab, plus Settings-Walk + View-Task. Läuft nur lokal, nicht in CI.
2. ⏳ **Mindestens ein echter Phasen-Lauf** (`concept` → `implement` → `push`) gegen ein
   Test-Repo, um den neuen `PhaseCommandBuilder`/`PhaseResultSync`/`UsageLimitManager`-
   Pfad mit echtem Worker-Container + Volume-I/O zu bestätigen (die Pest-Tests mocken
   `newProcess`/`WorkerVolumeReader`). Braucht echte Tokens — manuell auf der Stage.
3. ⏳ **Ein echter Demo-Deploy** (`live_demo_enabled`), um `TraefikRouter` (Route-Datei im
   echten `traefik_dir` + Reachability) und `DemoComposeBuilder` (echtes `compose up`)
   end-to-end zu prüfen. Manuell auf der Stage.

Schritt 1 (die eigentliche Absicherung des umgebauten Workflows) ist grün → der Branch
ist aus PHP- + Browser-Sicht merge-reif; Schritte 2–3 brauchen echte Tokens und laufen
idealerweise einmal manuell auf der Stage (`argos-stage`).

> **Hinweis Doku-Drift**: Die CLAUDE.md beschreibt Browser-E2E als „Playwright bootet
> `php artisan serve` selbst — kein laufender Stack nötig". Die tatsächliche
> `playwright.config.ts` braucht aber den **laufenden Compose-Stack** (nginx auf
> `ARGOS_PORT`, inkl. Queue/Redis, `ARGOS_E2E_FAKE=1`), weil der Full-Flow die Queue
> braucht. Vor dem nächsten E2E-Lauf die CLAUDE.md angleichen (per `/retro`).

---

Jeder Schritt war für sich lauffähig und getestet — pro Extraktion erst Test (bzw.
bestehenden Filter grün), dann Schnitt.
