# Argos REST API

Argos stellt eine versionierte Server-zu-Server-REST-API bereit, mit der sich
dieselben Workspaces steuern lassen, die Sie auch in der Web-UI verwenden —
Workspaces auflisten, **automatische Tasks** starten, die planen,
implementieren, fehlschlagende Quality-Gates beheben und einen Pull Request
öffnen, und diese aus eigenen Skripten, CI-Pipelines oder anderer
Automatisierung heraus lenken.

Die REST-API läuft gegen exakt dieselbe Workspace-Logik wie die Argos-UI und
der [MCP-Server](SETUP-MCP.md).

Wenn Sie Argos lieber aus einem KI-Agenten heraus steuern möchten (Claude
Desktop, eine IDE usw.), statt selbst HTTP-Aufrufe zu schreiben, lesen Sie
[SETUP-MCP.md](SETUP-MCP.md) zum MCP-Server — er ist die alternative
Automatisierungsschnittstelle auf dieselben Workspaces.

## Basis-URL und Versionierung

Alle Endpunkte liegen unter dem Präfix `/api/v1`:

```
https://your-argos-host/api/v1
```

`v1` ist die aktuelle und einzige API-Version. Ersetzen Sie `your-argos-host`
durch den Host, unter dem Ihre Argos-Instanz erreichbar ist.

## Authentifizierung

Die API verwendet **Sanctum-Bearer-Tokens**. Jede Anfrage muss das Token in
einem `Authorization: Bearer <token>`-Header mitsenden. Tokens tragen zudem
**Abilities** (Scopes), die festlegen, welche Endpunkte sie aufrufen dürfen.

### Token-Arten: API-Client vs. workspacegebunden

Ein Token ist an einen von zwei Besitzern gebunden, was seine Reichweite
bestimmt:

- **API-Client-Token** — gebunden an einen *API-Client*, einen Eintrag, der
  einen benannten Konsumenten der API repräsentiert. Es handelt für den Nutzer
  unter **Handelt als** des Clients und sieht jeden Workspace, den diese Person
  sehen darf (ein Argos-Admin sieht alle).
- **Workspacegebundenes Token** — gebunden an einen einzelnen *Workspace*. Es
  ist auf genau diesen Workspace beschränkt: Anfragen für einen anderen
  Workspace oder dessen Tasks liefern `404`.

Unabhängig vom Besitzer steuern die **Abilities** des Tokens nach wie vor jeden
Endpunkt.

### Ein Token in der UI erstellen

Tokens werden im Argos-Adminpanel erzeugt. Das Klartext-Token wird bei der
Erstellung **einmalig** angezeigt — kopieren Sie es sofort, denn nur sein Hash
wird gespeichert und es lässt sich nicht erneut abrufen.

**Für ein API-Client-Token:**

1. Öffnen Sie das Adminpanel und gehen Sie zu **API Clients** (unter der
   Navigationsgruppe Configuration).
2. Erstellen Sie einen API-Client (geben Sie ihm einen aussagekräftigen Namen
   und einen Nutzer unter **Handelt als**) oder öffnen Sie einen bestehenden.
3. Verwenden Sie auf der Seite des Clients den Abschnitt **API tokens**, um ein
   Token zu generieren: Geben Sie ihm einen Namen und haken Sie die Abilities
   an, die es tragen soll.
4. Kopieren Sie das Klartext-Token aus der einmaligen Benachrichtigung.

**Für ein workspacegebundenes Token:**

1. Öffnen Sie im Adminpanel den **Workspace**, auf den Sie das Token
   beschränken möchten.
2. Verwenden Sie denselben Abschnitt **API tokens** auf der Seite des
   Workspaces, um ein Token mit einem Namen und den gewünschten Abilities zu
   generieren.
3. Kopieren Sie das Klartext-Token aus der einmaligen Benachrichtigung.

### Abilities

Ein Token trägt eine oder mehrere dieser Abilities. Sie spiegeln die
Route-Gates exakt wider — eine Anfrage an einen Endpunkt, dessen erforderliche
Ability dem Token fehlt, wird mit `403` abgewiesen.

| Ability | Gewährt Zugriff auf |
| --- | --- |
| `workspaces:read` | Workspaces auflisten und lesen |
| `tasks:read` | Die Tasks eines Workspaces auflisten und einen Task lesen |
| `tasks:write` | Automatische Tasks starten, in Tasks schreiben, Pläne entscheiden, pausieren/fortsetzen, ausliefern |

### Für wen eine Anfrage handelt

Jede Anfrage handelt für eine **Person**: Deren Claude-Agent (Abo) arbeitet,
ihr gehören die Tasks, die dabei entstehen, und ihre Workspace-Rolle
entscheidet, was sie darf.

- Ein **workspacegebundenes Token** handelt für den **Automatik-Nutzer** des
  Workspaces (Workspace → Tab *Automatik*).
- Ein **API-Client-Token** handelt für den Nutzer unter **Handelt als** im
  Formular des API-Clients.

Ohne diesen Nutzer antworten Anfragen mit `422` und `"code": "no_actor"`.

Was die Person darf, folgt ihrer Workspace-Rolle: `admin` und `member` dürfen
Tasks starten, ein `viewer` liest nur. Alle in einem Workspace sehen alle seine
Tasks, aber nur die Besitzerin eines Tasks und seine Mitwirkenden (oder ein
Workspace-Admin) dürfen hineinschreiben, ihn lenken oder ausliefern — andere
Tasks antworten mit `403`.

### Das Token verwenden

Senden Sie es bei jeder Anfrage als Bearer-Token mit:

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpunkte

Ein Pfadparameter `{workspace}` oder `{task}` akzeptiert die ULID der
jeweiligen Ressource.

| Methode | Pfad | Ability | Zweck |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces der handelnden Person (Filter: `status`) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | Ein Workspace |
| GET | `/api/v1/workspaces/{workspace}/tasks` | `tasks:read` | Die Tasks des Workspaces, neueste zuerst (Filter: `origin`, `archived`) |
| GET | `/api/v1/tasks/{task}` | `tasks:read` | Ein Task mit Branch, Pull Request, Gates, Ticket und dem Zustand seines automatischen Laufs |
| POST | `/api/v1/workspaces/{workspace}/tasks` | `tasks:write` | Einen automatischen Task starten |
| POST | `/api/v1/tasks/{task}/messages` | `tasks:write` | In einen Task schreiben (`text`); pausiert einen laufenden automatischen Task, außer `resume_auto` ist true |
| POST | `/api/v1/tasks/{task}/plan` | `tasks:write` | Einen wartenden Plan entscheiden: `decision` = `approve` oder `reject`, optional `feedback` |
| POST | `/api/v1/tasks/{task}/pause` | `tasks:write` | Automatik pausieren |
| POST | `/api/v1/tasks/{task}/resume` | `tasks:write` | Den Task an die Automatik zurückgeben |
| POST | `/api/v1/tasks/{task}/ship` | `tasks:write` | Jetzt committen, pushen und den Pull Request des Tasks öffnen/aktualisieren (`draft`) |

### Workspaces

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "my-service",
      "repository": "acme/my-service",
      "platform": "github",
      "default_branch": "main",
      "status": "running",
      "url": "https://your-argos-host/w/workspaces/01J...",
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` ist einer der Werte `github`, `gitlab`, `bitbucket`. `status` ist
der Zustand des Containers des Workspaces (`stopped`, `running`, …).

### Tasks

Ein **Task** ist ein Arbeitspaket in einem Workspace — ein Gespräch mit Claude
auf einem eigenen Branch, mit eigenem Pull Request. Jeder Task hat eine
Besitzerin (`owner`), einen `status` (`preparing`, `running`, `waiting`,
`ready`, `pr_open`, `done`, `cancelled` oder `archived`), einen `branch` und
`base_branch`, den Pull Request (`pull_request`: `url`, `number`, `state`), die
letzten `gates`, das verknüpfte `ticket` und, bei einem automatischen Task,
seinen Zustand unter `auto`.

Ein **automatischer Task** plant (optional), setzt um, behebt fehlgeschlagene
Quality-Gates und liefert aus — siehe
[Workspaces → Auto-Threads](WORKSPACES.md#auto-threads). Er läuft asynchron:
Schreibende Endpunkte antworten mit `202`, den Fortschritt fragen Sie am Task
ab.

**Automatischen Task starten** — `POST /api/v1/workspaces/{workspace}/tasks`:

| Feld | Pflicht | Bedeutung |
| --- | --- | --- |
| `prompt` | ja | Was umgesetzt werden soll (Markdown) |
| `title` | nein | Titel des Tasks (Standard: Titel des Tickets oder Anfang des Prompts) |
| `base_branch` | nein | Ausgangs-Branch (Standard: Standard-Branch des Workspaces) |
| `issue` | nein | Ticket-Nummer im Issue-Tracker des Workspaces: benennt den Branch danach, `Closes #…` im Pull Request |
| `agent_id` | nein | Einer der nutzbaren Agenten der handelnden Person (Standard: der erste) |
| `model` | nein | Modell-ID oder Alias (`sonnet`, `opus`, …) |
| `plan` | nein | `off`, `auto` oder `approve` (Standard: Workspace-Einstellung) |
| `ship` | nein | `pr`, `draft_pr` oder `none` (Standard: Workspace-Einstellung) |
| `max_fix_rounds`, `max_turns`, `max_cost_usd`, `max_minutes` | nein | Budgets (Standard: Workspace-Einstellung) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces/01J.../tasks \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" -H "Accept: application/json" \
  -d prompt="Notizen bekommen eine Priorität (niedrig/mittel/hoch)" -d plan=approve
```

Ein gestoppter Workspace wird gestartet; der Task wartet in der Queue, bis er
läuft. `auto.state` des Tasks läuft `queued → planning →
(awaiting_plan_approval) → implementing → gating ⇄ fixing → shipping → done`;
er kann auch mit `failed` oder `budget_exceeded` enden oder von einer Person
`paused` werden. Der Pull Request erscheint unter `pull_request`.

Fachliche Fehler antworten mit `422` und einem `code`: `no_actor`,
`no_usable_agent`, `no_plan_waiting`, `capacity` (zu viele laufende Workspaces)
und weitere, benannt nach dem Fehler. Eine verweigerte Berechtigung antwortet
mit `403` und `"code": "forbidden"`.

## Typischer Ablauf

1. `GET /api/v1/workspaces` — den Workspace finden, in dem gearbeitet werden
   soll.
2. `POST /api/v1/workspaces/{workspace}/tasks` — einen automatischen Task mit
   dem starten, was umgesetzt werden soll.
3. `GET /api/v1/tasks/{task}` pollen und seinen `auto.state` verfolgen.
4. Mit `plan` = `approve` den Plan entscheiden, sobald der Zustand
   `awaiting_plan_approval` ist: `POST /api/v1/tasks/{task}/plan`.
5. Ist der Zustand `done`, steht der Pull Request unter `pull_request`.
6. `POST /api/v1/tasks/{task}/messages` — Feedback in den Task schicken (mit
   `resume_auto`, damit der Lauf selbstständig weitermacht).

## Antworten und Fehler

- Erfolgreiche Lesezugriffe liefern `200` mit der in einer `data`-Hülle
  verpackten Ressource (einzelnes Objekt) oder einem `data`-Array
  (Sammlungen).
- Schreibaktionen, die asynchrone Arbeit anstoßen, liefern `202 Accepted` mit
  der Ressource in der `data`-Hülle. Die Arbeit läuft im Hintergrund — lesen
  Sie den Task erneut, um ihr zu folgen.
- Fehler liefern einen JSON-Body mit einem `message`-Feld und einem passenden
  HTTP-Status.

| Status | Bedeutung |
| --- | --- |
| `401` | Fehlendes oder ungültiges Token. |
| `403` | Dem Token fehlt die für diesen Endpunkt erforderliche Ability, oder die handelnde Person darf das nicht (`"code": "forbidden"`). |
| `404` | Ressource nicht gefunden — oder vor einem workspacegebundenen Token bzw. der handelnden Person verborgen. |
| `422` | Validierungsfehler (fehlende/ungültige Felder) oder ein fachlicher Fehler mit `code` (siehe oben). |

Ein Beispiel für eine `422`-Validierung:

```json
{
  "message": "The prompt field is required.",
  "errors": {
    "prompt": ["The prompt field is required."]
  }
}
```

## Interaktive API-Referenz

Argos liefert eine automatisch generierte, interaktive OpenAPI-Referenz
(Scramble) unter:

```
https://your-argos-host/docs/api
```

Das rohe OpenAPI-Dokument ist unter `/docs/api.json` verfügbar. Der Zugriff auf
die Dokumentation ist auf angemeldete Argos-Benutzer beschränkt.

## Siehe auch

- [SETUP-MCP.md](SETUP-MCP.md) — der MCP-Server, die alternative
  Automatisierungsschnittstelle auf dieselben Workspaces, zum Steuern von
  Argos aus einem KI-Agenten heraus.
- [WORKSPACES.md](WORKSPACES.md#auto-threads) — wie automatische Tasks
  funktionieren.
