# Argos REST API

Argos stellt eine versionierte Server-zu-Server-REST-API bereit, mit der sich
derselbe Task-Workflow steuern lässt, den Sie auch in der Web-UI verwenden —
Projekte auflisten, Tasks erstellen und sie durch die Phasen Concept →
Implement → Push (PR) führen, aus eigenen Skripten, CI-Pipelines oder anderer
Automatisierung heraus.

Die REST-API läuft gegen exakt dieselbe `TaskService`-Logik wie die Argos-UI
und der [MCP-Server](SETUP-MCP.md). Alles, was Sie durch Klicken durch einen
Task tun können, können Sie also auch über HTTP erledigen.

Wenn Sie Argos lieber aus einem KI-Agenten heraus steuern möchten (Claude
Desktop, eine IDE usw.), statt selbst HTTP-Aufrufe zu schreiben, lesen Sie
[SETUP-MCP.md](SETUP-MCP.md) zum MCP-Server — er ist die alternative
Automatisierungsschnittstelle auf denselben Workflow.

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
| `tasks:read` | Tasks auflisten und lesen |
| `tasks:write` | Tasks erstellen und Phasen ausführen/fortsetzen (feedback, concept, implement, pr) |

### Das Token verwenden

Senden Sie es bei jeder Anfrage als Bearer-Token mit:

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpunkte

Ein Pfadparameter `{task}` akzeptiert die ULID des Tasks. Ein Pfadparameter
`{repoProfile}` akzeptiert die ULID des Projekts.

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
      "auto_concept": false,
      "auto_pr": false,
      "open_tasks": 2,
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` ist einer der Werte `github`, `gitlab`, `bitbucket`.

### Ein Projekt abrufen

```
GET /api/v1/projects/{repoProfile}
```

Erfordert `projects:read`. Liefert ein einzelnes Projekt in derselben Form wie
der Listeneintrag oben. Ein projektgebundenes Token darf nur sein eigenes
Projekt lesen; jede andere id liefert `404`.

### Tasks auflisten

```
GET /api/v1/tasks
```

Erfordert `tasks:read`. Liefert Tasks neueste zuerst. Optionale
Query-Parameter:

| Parameter | Beschreibung |
| --- | --- |
| `project` | Nach Projekt-id oder Projektname filtern. Wird bei projektgebundenen Tokens ignoriert (bereits auf ihr Projekt beschränkt). |
| `status` | Nach `workflow_status`-Wert filtern (siehe unten). |

```bash
curl "https://your-argos-host/api/v1/tasks?project=my-service&status=in_review" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "Add rate limiting",
      "project": { "id": "01J...", "name": "my-service" },
      "workflow_status": "in_review",
      "current_phase": "push",
      "current_status": "completed",
      "feature_branch": "argos/add-rate-limiting",
      "pr_url": "https://github.com/acme/my-service/pull/42",
      "created_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`workflow_status` ist einer der Werte: `draft`, `concept_running`,
`concept_review`, `implement_running`, `implement_paused`,
`implement_completed`, `in_review`, `completed`, `failed`, `aborted`.

### Einen Task abrufen

```
GET /api/v1/tasks/{task}
```

Erfordert `tasks:read`. Liefert das vollständige Task-Detail, einschließlich der
Concept- und Implement-Texte, eines `checkout`-Blocks zum lokalen Klonen des
Ergebnisses sowie der letzten Phasenläufe.

```bash
curl https://your-argos-host/api/v1/tasks/01J... \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": {
    "id": "01J...",
    "name": "Add rate limiting",
    "description": "The plan text...",
    "workflow_status": "in_review",
    "current_phase": "push",
    "current_status": "completed",
    "project": { "id": "01J...", "name": "my-service" },
    "concept_md": "# Concept\n...",
    "concept_notes": "The plan text...",
    "implement_summary_nontechnical": "...",
    "implement_summary_technical": "...",
    "checkout": {
      "repo_url": "https://github.com/acme/my-service",
      "base_branch": "main",
      "feature_branch": "argos/add-rate-limiting"
    },
    "pr_url": "https://github.com/acme/my-service/pull/42",
    "phase_runs": [
      {
        "phase": "push",
        "iteration": 1,
        "status": "completed",
        "started_at": "2026-06-13T11:00:00.000000Z",
        "finished_at": "2026-06-13T11:05:00.000000Z"
      }
    ],
    "created_at": "2026-06-13T10:00:00.000000Z",
    "updated_at": "2026-06-13T11:05:00.000000Z"
  }
}
```

### Einen Task erstellen

```
POST /api/v1/tasks
```

Erfordert `tasks:write`. Erstellt einen Task aus einem Plan und **startet
sofort die Concept-Phase**. Da Phasen asynchron laufen, liefert dies umgehend
`202 Accepted` mit dem neuen Task zurück; pollen Sie
`GET /api/v1/tasks/{task}`, um den Fortschritt zu verfolgen.

Request-Body:

| Feld | Erforderlich | Beschreibung |
| --- | --- | --- |
| `name` | ja | Task-Name (max. 255 Zeichen). |
| `plan` | ja | Der Plan. Wird sowohl als Task-Beschreibung als auch als Concept-Notizen gespeichert. |
| `project` | bedingt | Projekt-id oder -name. Erforderlich für Tokens mit Vollzugriff; optional für projektgebundene Tokens. Wird er mit einem projektgebundenen Token angegeben, muss er dem Projekt des Tokens entsprechen. |
| `base_branch` | nein | Branch, auf dem die Arbeit basieren soll (max. 255 Zeichen). Standardmäßig der Default-Branch des Projekts. |

```bash
curl -X POST https://your-argos-host/api/v1/tasks \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
        "name": "Add rate limiting",
        "plan": "Add a rate limiter to the public API...",
        "project": "my-service",
        "base_branch": "main"
      }'
```

Antwortet mit `202 Accepted` und dem Task in derselben Form wie bei **Einen
Task abrufen**.

### Feedback einreichen

```
POST /api/v1/tasks/{task}/feedback
```

Erfordert `tasks:write`. Sendet Review-Feedback, das die Respond-Phase ausführt.

| Feld | Erforderlich | Beschreibung |
| --- | --- | --- |
| `feedback` | ja | Der Feedback-Text. |

```bash
curl -X POST https://your-argos-host/api/v1/tasks/01J.../feedback \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "feedback": "Please also cover the websocket route." }'
```

Antwortet mit `202 Accepted` und dem Task.

### Concept-Phase ausführen / fortsetzen

```
POST /api/v1/tasks/{task}/concept
```

Erfordert `tasks:write`. Startet die Concept-Phase oder — falls der vorherige
Concept-Lauf pausiert ist — setzt ihn fort.

| Feld | Erforderlich | Beschreibung |
| --- | --- | --- |
| `max_turns` | nein | Ganzzahl 10–1000. Gilt nur beim Fortsetzen eines pausierten Laufs; andernfalls werden Standardwerte verwendet. |

Antwortet mit `202 Accepted` und dem Task.

### Implement-Phase ausführen / fortsetzen

```
POST /api/v1/tasks/{task}/implement
```

Erfordert `tasks:write`. Startet die Implement-Phase oder setzt einen pausierten
Implement-Lauf fort. **Erfordert zuvor einen abgeschlossenen Concept-Lauf** —
andernfalls wird `409` zurückgegeben.

| Feld | Erforderlich | Beschreibung |
| --- | --- | --- |
| `max_turns` | nein | Ganzzahl 10–1000. Gilt nur beim Fortsetzen eines pausierten Laufs. |

Antwortet mit `202 Accepted` und dem Task.

### Den Pull Request öffnen (Push-Phase)

```
POST /api/v1/tasks/{task}/pr
```

Erfordert `tasks:write`. Führt die Push-Phase aus, die den Pull Request öffnet.
**Erfordert zuvor einen abgeschlossenen Implement-Lauf** — andernfalls wird
`409` zurückgegeben.

```bash
curl -X POST https://your-argos-host/api/v1/tasks/01J.../pr \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

Antwortet mit `202 Accepted` und dem Task. Sobald die Push-Phase abgeschlossen
ist, lesen Sie den Task erneut, um die `pr_url` und den `checkout`-Block zu
finden.

## Typischer Ablauf

1. `GET /api/v1/projects` — das Projekt finden, in dem gearbeitet werden soll.
2. `POST /api/v1/tasks` — einen Task mit dem Plan erstellen (Concept startet
   automatisch).
3. `GET /api/v1/tasks/{task}` pollen, bis `workflow_status` den Wert
   `concept_review` erreicht.
4. `POST /api/v1/tasks/{task}/implement` — die Implementierung ausführen;
   erneut pollen.
5. `POST /api/v1/tasks/{task}/pr` — den Pull Request öffnen; auf `pr_url`
   pollen.
6. `POST /api/v1/tasks/{task}/feedback` — bei Bedarf Review-Feedback senden.

## Antworten und Fehler

- Erfolgreiche Lesezugriffe liefern `200` mit der in einer `data`-Hülle
  verpackten Ressource (einzelnes Objekt) oder einem `data`-Array
  (Sammlungen).
- Schreibaktionen, die eine asynchrone Phase anstoßen, liefern `202 Accepted`
  mit dem Task in der `data`-Hülle. Die Arbeit läuft im Hintergrund — lesen Sie
  den Task erneut, um ihr zu folgen.
- Fehler liefern einen JSON-Body mit einem `message`-Feld und einem passenden
  HTTP-Status.

| Status | Bedeutung |
| --- | --- |
| `401` | Fehlendes oder ungültiges Token. |
| `403` | Dem Token fehlt die für diesen Endpunkt erforderliche Ability. |
| `404` | Ressource nicht gefunden — oder vor einem projektgebundenen Token verborgen. |
| `409` | Konfliktzustand, z. B. eine Phase läuft bereits, der Task ist abgeschlossen, oder eine erforderliche vorherige Phase ist nicht abgeschlossen. |
| `422` | Validierungsfehler (fehlende/ungültige Felder). |

Ein Beispiel für einen `409`-Konflikt:

```json
{
  "message": "A phase is already running for this task."
}
```

Ein Beispiel für eine `422`-Validierung:

```json
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."]
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
  Automatisierungsschnittstelle auf denselben Task-Workflow, zum Steuern von
  Argos aus einem KI-Agenten heraus.
