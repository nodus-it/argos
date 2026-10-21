# Argos REST API

Argos stellt eine versionierte Server-zu-Server-REST-API bereit, mit der sich
dieselben Workspaces steuern lassen, die Sie auch in der Web-UI verwenden —
Projekte auflisten, **automatische Läufe** starten, die planen, implementieren,
fehlschlagende Quality-Gates beheben und einen Pull Request öffnen, und diese
aus eigenen Skripten, CI-Pipelines oder anderer Automatisierung heraus lenken.

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

### Token-Arten: voll vs. projektgebunden

Ein Token ist an einen von zwei Besitzern gebunden, was seine Reichweite
bestimmt:

- **API-Client-Token (Vollzugriff)** — gebunden an einen *API-Client*, einen
  Eintrag, der einen benannten Konsumenten der API repräsentiert. Diese Tokens
  können **alle** Projekte einsehen und auf ihnen agieren.
- **Projektgebundenes Token** — gebunden an ein einzelnes *Projekt*
  (Repo-Profil). Diese Tokens sind auf genau dieses eine Projekt beschränkt:
  Anfragen für die Daten eines anderen Projekts liefern `404`, und das Feld
  `project` wird gegen das eigene Projekt des Tokens aufgelöst (und dafür
  validiert).

Unabhängig vom Besitzer steuern die **Abilities** des Tokens nach wie vor jeden
Endpunkt.

### Ein Token in der UI erstellen

Tokens werden im Argos-Adminpanel erzeugt. Das Klartext-Token wird bei der
Erstellung **einmalig** angezeigt — kopieren Sie es sofort, denn nur sein Hash
wird gespeichert und es lässt sich nicht erneut abrufen.

**Für ein Token mit Vollzugriff:**

1. Öffnen Sie das Adminpanel und gehen Sie zu **API Clients** (unter der
   Navigationsgruppe Configuration).
2. Erstellen Sie einen API-Client (geben Sie ihm einen aussagekräftigen Namen)
   oder öffnen Sie einen bestehenden.
3. Verwenden Sie auf der Seite des Clients den Abschnitt **API tokens**, um ein
   Token zu generieren: Geben Sie ihm einen Namen und haken Sie die Abilities
   an, die es tragen soll.
4. Kopieren Sie das Klartext-Token aus der einmaligen Benachrichtigung.

**Für ein projektgebundenes Token:**

1. Öffnen Sie das **Projekt** (Repo-Profil), auf das Sie das Token beschränken
   möchten.
2. Verwenden Sie denselben Abschnitt **API tokens** auf der Projektseite, um ein
   Token mit einem Namen und den gewünschten Abilities zu generieren.
3. Kopieren Sie das Klartext-Token aus der einmaligen Benachrichtigung.

### Abilities

Ein Token trägt eine oder mehrere dieser Abilities. Sie spiegeln die
Route-Gates exakt wider — eine Anfrage an einen Endpunkt, dessen erforderliche
Ability dem Token fehlt, wird abgewiesen.

| Ability | Gewährt Zugriff auf |
| --- | --- |
| `projects:read` | Projekte auflisten und lesen |
| `workspaces:read` | Workspaces und ihre Threads auflisten und lesen |
| `workspaces:write` | Automatische Läufe starten, in Threads schreiben, Pläne entscheiden, pausieren/fortsetzen, ausliefern |

### Für wen eine Workspace-Anfrage handelt

Workspace-Endpunkte handeln immer für eine **Person**: Deren Claude-Agent (Abo)
arbeitet, und ihr gehören die Workspaces, die dabei entstehen.

- Ein **projektgebundenes Token** handelt für den **Automatik-Nutzer** des
  Projekts (Projekt → Tab *Automatik*).
- Ein **API-Client-Token** handelt für den Nutzer unter **Handelt als** im
  Formular des API-Clients.

Ohne diesen Nutzer antworten Workspace-Anfragen mit `422` und `"code": "no_actor"`.

### Das Token verwenden

Senden Sie es bei jeder Anfrage als Bearer-Token mit:

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpunkte

Ein Pfadparameter `{repoProfile}`, `{workspace}` oder `{thread}` akzeptiert
die ULID der jeweiligen Ressource.

### Projekte auflisten

```
GET /api/v1/projects
```

Erfordert `projects:read`. Liefert alle Projekte (oder nur das gebundene
Projekt, bei einem projektgebundenen Token), nach Name sortiert.

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "my-service",
      "url": "https://github.com/acme/my-service",
      "platform": "github",
      "default_branch": "main",
      "auto_settings": {
        "plan": "auto",
        "ship": "pr",
        "max_fix_rounds": 3,
        "max_turns": 60,
        "max_cost_usd": 0,
        "max_minutes": 120
      },
      "open_workspaces": 2,
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` ist einer der Werte `github`, `gitlab`, `bitbucket`.
`auto_settings` sind die wirksamen Vorgaben für automatische Läufe in diesem
Projekt (Installationsvorgaben, überschrieben durch den Tab *Automation* des
Projekts). `open_workspaces` zählt die Workspaces des Projekts, die weder
gestoppt noch fehlgeschlagen sind.

### Ein Projekt abrufen

```
GET /api/v1/projects/{repoProfile}
```

Erfordert `projects:read`. Liefert ein einzelnes Projekt in derselben Form wie
der Listeneintrag oben. Ein projektgebundenes Token darf nur sein eigenes
Projekt lesen; jede andere id liefert `404`.

### Workspaces und automatische Läufe

Ein **automatischer Lauf** ist ein Thread, der (optional) plant, umsetzt,
fehlgeschlagene Quality-Gates behebt und ausliefert — siehe
[Workspaces → Auto-Threads](WORKSPACES.md#auto-threads). Läufe sind asynchron:
Schreibende Endpunkte antworten mit `202`, den Fortschritt fragst du am Workspace ab.

| Methode | Pfad | Ability | Zweck |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces der handelnden Person (Filter: `origin`, `status`) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | Ein Workspace mit Threads, Pull Request und letzten Gate-Ergebnissen |
| GET | `/api/v1/threads/{thread}` | `workspaces:read` | Ein Thread mit dem Zustand seines automatischen Laufs |
| POST | `/api/v1/workspaces` | `workspaces:write` | Workspace anlegen und einen automatischen Lauf starten |
| POST | `/api/v1/workspaces/{workspace}/threads` | `workspaces:write` | Einen weiteren automatischen Lauf in einem bestehenden Workspace starten |
| POST | `/api/v1/workspaces/{workspace}/ship` | `workspaces:write` | Jetzt committen, pushen und den Pull Request öffnen/aktualisieren (`draft`) |
| POST | `/api/v1/threads/{thread}/messages` | `workspaces:write` | In einen Thread schreiben (`text`); pausiert einen laufenden automatischen Lauf, außer `resume_auto` ist true |
| POST | `/api/v1/threads/{thread}/plan` | `workspaces:write` | Einen wartenden Plan entscheiden: `decision` = `approve` oder `reject`, optional `feedback` |
| POST | `/api/v1/threads/{thread}/pause` | `workspaces:write` | Automatik pausieren |
| POST | `/api/v1/threads/{thread}/resume` | `workspaces:write` | Den Thread an die Automatik zurückgeben |

**Lauf starten** — `POST /api/v1/workspaces`:

| Feld | Pflicht | Bedeutung |
| --- | --- | --- |
| `project` | ja | ULID oder exakter Name des Projekts |
| `prompt` | ja | Was umgesetzt werden soll (Markdown) |
| `title` | nein | Titel von Workspace/Thread (Standard: Anfang des Prompts) |
| `base_branch` | nein | Ausgangs-Branch (Standard: Standard-Branch des Projekts) |
| `issue` | nein | Issue-Nummer im Issue-Tracker des Projekts: benennt Workspace und Branch danach, `Closes #…` im Pull Request |
| `agent_id` | nein | Einer der nutzbaren Agenten der handelnden Person (Standard: der erste) |
| `model` | nein | Modell-ID oder Alias (`sonnet`, `opus`, …) |
| `plan` | nein | `off`, `auto` oder `approve` (Standard: Projekteinstellung) |
| `ship` | nein | `pr`, `draft_pr` oder `none` (Standard: Projekteinstellung) |
| `max_fix_rounds`, `max_turns`, `max_cost_usd`, `max_minutes` | nein | Budgets (Standard: Projekteinstellung) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" -H "Accept: application/json" \
  -d project=widget -d prompt="Notizen bekommen eine Priorität (niedrig/mittel/hoch)" -d plan=approve
```

`auto.state` des Threads läuft `queued → planning → (awaiting_plan_approval) →
implementing → gating ⇄ fixing → shipping → done`; er kann auch mit `failed`
oder `budget_exceeded` enden oder von einer Person `paused` werden. Der Pull
Request erscheint unter `pull_request`.

Fachliche Fehler antworten mit `422` und einem `code`: `no_actor`,
`no_usable_agent`, `no_plan_waiting`, `capacity` (zu viele laufende Workspaces)
und weitere, benannt nach dem Fehler.

## Typischer Ablauf

1. `GET /api/v1/projects` — das Projekt finden, in dem gearbeitet werden soll.
2. `POST /api/v1/workspaces` — einen automatischen Lauf mit dem starten, was
   umgesetzt werden soll.
3. `GET /api/v1/workspaces/{workspace}` pollen und `auto.state` des Threads
   verfolgen.
4. Mit `plan` = `approve` den Plan entscheiden, sobald der Zustand
   `awaiting_plan_approval` ist: `POST /api/v1/threads/{thread}/plan`.
5. Ist der Zustand `done`, steht der Pull Request unter `pull_request`.
6. `POST /api/v1/threads/{thread}/messages` — Feedback in den Thread schicken
   (mit `resume_auto`, damit der Lauf selbstständig weitermacht).

## Antworten und Fehler

- Erfolgreiche Lesezugriffe liefern `200` mit der in einer `data`-Hülle
  verpackten Ressource (einzelnes Objekt) oder einem `data`-Array
  (Sammlungen).
- Schreibaktionen, die asynchrone Arbeit anstoßen, liefern `202 Accepted` mit
  der Ressource in der `data`-Hülle. Die Arbeit läuft im Hintergrund — lesen
  Sie den Workspace erneut, um ihr zu folgen.
- Fehler liefern einen JSON-Body mit einem `message`-Feld und einem passenden
  HTTP-Status.

| Status | Bedeutung |
| --- | --- |
| `401` | Fehlendes oder ungültiges Token. |
| `403` | Dem Token fehlt die für diesen Endpunkt erforderliche Ability. |
| `404` | Ressource nicht gefunden — oder vor einem projektgebundenen Token verborgen. |
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
- [WORKSPACES.md](WORKSPACES.md#auto-threads) — wie automatische Läufe
  funktionieren.
