# Tickets

Eine [Aufgabe](TASKS.md) kann mit **genau einem Ticket** verknüpft werden — einem
Issue in GitHub, GitLab oder Linear oder einem Ticket deines eigenen Systems.
Das Ticket gibt der Aufgabe Titel und Beschreibung, benennt ihren Branch und
erfährt, was passiert: gestartet, Plan wartet, Pull Request, fertig oder
abgebrochen. Der Switcher, der Kopf der Aufgabe und
[Meine Arbeit](WORKSPACES.md#der-bereich-w) zeigen das Ticket immer als Link
(*GitLab #42*, *ENG-118*).

Tickets kommen aus den **Integrationen** des Workspaces (Einstellungen →
Integrationen → Issue-Tracker). Wie du einen Tracker verbindest und wie ein
neues Issue von selbst eine automatische Aufgabe startet, steht unter
[Issue-Trigger](SETUP-TASK-PROVIDERS.md).

## Inhalt

- [Ticket auswählen, verknüpfen und anlegen](#ticket-auswahlen-verknupfen-und-anlegen)
- [Was am Ticket ankommt](#was-am-ticket-ankommt)
- [Schließen beim Merge](#schliessen-beim-merge)
- [Ticket-Kommentare in der Aufgabe](#ticket-kommentare-in-der-aufgabe)
- [Übergabe und zugewiesene Person](#ubergabe-und-zugewiesene-person)
- [Automatische Aufgaben aus Issues](#automatische-aufgaben-aus-issues)
- [Eigene Ticket-Systeme](#eigene-ticket-systeme)
- [Unterstützung je Provider](#unterstutzung-je-provider)

## Ticket auswählen, verknüpfen und anlegen

Die Ticket-Funktionen nutzen die erste **aktive** GitHub-, GitLab- oder
Linear-Integration des Workspaces, bei der ein Projekt oder Team gesetzt ist.

- **Beim Start einer Aufgabe:** Das Feld *Ticket* von **Neue Aufgabe** sucht
  die offenen Issues nach Titel oder Nummer. Die Aufgabe übernimmt den Titel
  des Issues, ihr Branch bekommt den Ticket-Schlüssel (`gitlab-42-…`,
  `eng-118-…`), und der Composer wird mit Titel, Text und Link des Issues
  **vorbefüllt — nicht gesendet** —, verpackt als nicht vertrauenswürdige
  Eingabe, damit Claude sie als Daten behandelt, nicht als Anweisungen.
- **Nachträglich:** **⋯ → Mit Ticket verknüpfen** verknüpft ein bestehendes
  Issue mit einer Aufgabe, die noch keins hat (das Issue bekommt einen
  Kommentar mit dem Link zur Aufgabe).
- **Aus der Aufgabe:** **⋯ → Ticket anlegen** legt im Tracker ein neues Issue
  mit deinem Titel und deiner Beschreibung an (plus einem Link zurück zur
  Aufgabe) und verknüpft es.
- Über die [REST API](REST-API.md#eine-aufgabe-starten) (`issue`) und
  [MCP](SETUP-MCP.md) (`task_create` mit `issue`): die Ticket-Nummer im
  Tracker des Workspaces.

Verknüpfen und Anlegen brauchen Schreibzugriff auf die Aufgabe (die Person,
der sie gehört, Mitarbeitende, Workspace-Admins). Das Ticket einer Aufgabe
lässt sich nicht mehr ändern, sobald es gesetzt ist; eine
[Folge-Aufgabe](TASKS.md#eine-aufgabe-abschliessen) behält dasselbe Ticket.

## Was am Ticket ankommt

Argos kommentiert am Ticket (eigene Systeme: ein
[Webhook](#eigene-ticket-systeme)), wenn

| Moment | Kommentar |
|---|---|
| Die Aufgabe startet mit dem Ticket | *Argos arbeitet an diesem Issue. Live verfolgen: …* |
| Ein Ticket wird nachträglich verknüpft | *Dieses Issue ist jetzt mit einer Aufgabe in Argos verknüpft: …* |
| Der Plan einer automatischen Aufgabe wartet auf Freigabe | Der Plan; ein 👍 auf diesen Kommentar gibt ihn frei (siehe [Plan-Freigabe](SETUP-TASK-PROVIDERS.md#kommentare-und-plan-freigabe)) |
| Der Pull Request wird geöffnet | Der Link zum Pull Request |
| Ein automatischer Lauf ist fehlgeschlagen oder hat sein Budget aufgebraucht | Der Grund und der Link zur Aufgabe |
| Die Aufgabe ist fertig (gemergt) oder abgebrochen | *Fertig — der Pull Request ist gemergt …* / *… ohne Merge abgebrochen …* |

Fehler beim Posten werden protokolliert und halten die Aufgabe nie an.

## Schließen beim Merge

Jede Integration hat **Ticket beim Merge schließen** (standardmäßig an):

| Tracker | An | Aus |
|---|---|---|
| GitHub, GitLab | Der Pull Request enthält `Closes #<number>`, daher schließt der Provider das Issue, wenn der Pull Request gemergt wird. | Der Pull Request enthält `Refs #<number>`; das Issue bleibt offen. |
| Linear | Argos schließt das Issue über die API, sobald die Aufgabe fertig ist. | Das Issue bleibt offen; der Pull Request trägt den Link zum Issue. |
| Eigenes System | Argos sendet das Event `done`; das Schließen übernimmt dein System. | Ebenso. |

Der Kommentar zu fertig/abgebrochen wird in beiden Fällen gepostet. `Closes #`
bezieht sich auf ein Issue im Repository des Pull Requests selbst — binde die
GitHub-/GitLab-Integration an das Repository des Workspaces, wenn Argos dort
Issues schließen soll.

## Ticket-Kommentare in der Aufgabe

Neue Kommentare am Ticket einer offenen Aufgabe (laufend oder mit offenem
Pull Request) erscheinen in der Aufgabe als Hinweis mit Name der verfassenden Person und Text —
sie werden **nie von selbst an Claude gesendet**; gib weiter, was wichtig ist.
Die Person, der die Aufgabe gehört, und die Mitarbeitenden bekommen eine
Benachrichtigung (*Neue Kommentare am Ticket meiner Aufgaben*).

- GitHub, GitLab, Linear: werden alle `ARGOS_POLL_INTERVAL_MINUTES` gelesen
  (Standard 5, `argos:tasks:sync-ticket-comments`). Der erste Blick merkt sich
  nur, wo das Gespräch steht; die eigenen Kommentare von Argos werden
  übersprungen.
- Eigene Systeme: poste sie mit
  [`POST /api/v1/tasks/{task}/ticket-comments`](#kommentare-posten).

## Übergabe und zugewiesene Person

Wird eine Aufgabe [übergeben](TASKS.md#zusammenarbeiten), folgt die dem
Ticket zugewiesene Person, wo der Tracker das kann:

| Tracker | Neu zugewiesen |
|---|---|
| GitHub, GitLab | Der Login des verbundenen GitHub-/GitLab-Accounts (OAuth) der Person, der die Aufgabe jetzt gehört. Ohne verbundenen Account ändert sich nichts. |
| Linear | Die Linear-Person mit der E-Mail-Adresse der Person, der die Aufgabe jetzt gehört. |
| Eigenes System | Das Event `owner_changed` mit Name und E-Mail der Person, der die Aufgabe jetzt gehört. |

## Automatische Aufgaben aus Issues

Startet ein [Issue-Trigger](SETUP-TASK-PROVIDERS.md) eine automatische
Aufgabe, gehört die Aufgabe

- der **dem Issue zugewiesenen Person**, wenn sie Admin oder Mitglied im
  Workspace ist, ein nutzbares Claude-Abo hat **und** in ihrem Profil
  zugestimmt hat: *Mir zugewiesene Issues dürfen automatisch auf meinem Abo
  starten*;
- sonst dem **Automatik-Nutzer** des Workspaces (Einstellungen → Automatik) —
  und die zugewiesene Person wird, wenn sie im Workspace ist, als
  Mitarbeitende eingeladen.

Zugewiesene Personen werden über den Login ihres verbundenen Accounts
(GitHub, GitLab) oder über die E-Mail (Linear) zugeordnet. Ohne
Automatik-Nutzer und ohne zustimmende zugewiesene Person startet nichts;
*Letzter Fehler* der Integration sagt das.

## Eigene Ticket-Systeme

Ein Helpdesk, ein interner Tracker oder irgendetwas anderes kann Aufgaben über
die REST API steuern und über einen signierten Webhook Rückmeldung bekommen.

### Verbinden

Wähle unter **Einstellungen → Integrationen → Issue-Tracker → Erstellen** die
Option **Eigenes System**:

| Feld | Bedeutung |
|---|---|
| Name des Systems | So heißt das System in Argos, z. B. *Support-Desk*. |
| Webhook-URL | Wohin Argos die Events der Aufgaben postet (optional — ohne URL wird nichts gesendet). |
| Signatur-Geheimnis | Wird beim Speichern der Integration erzeugt; beim Bearbeiten angezeigt (nur lesbar). |
| Ticket beim Merge schließen | Wird für jede Integration angezeigt; eigene Systeme bekommen das Event `done` in jedem Fall. |

Lege für das System ein [API-Token](REST-API.md#authentifizierung) mit
`tasks:write` an (und `tasks:read`, um zu pollen) — ein Workspace-Token
(Einstellungen → Integrationen → API-Tokens) oder ein Token eines API-Clients.

### Eine Aufgabe mit Ticket starten

`POST /api/v1/workspaces/{workspace}/tasks` mit einem `ticket`-Objekt startet
eine automatische Aufgabe, die mit deinem Ticket verknüpft ist (über die erste
Integration *Eigenes System* des Workspaces):

```json
{
  "prompt": "Fix the export for the customer described in the ticket.",
  "ticket": {
    "key": "SUP-1234",
    "title": "CSV export drops umlauts",
    "url": "https://desk.example.com/tickets/1234",
    "body": "Customer reports …"
  }
}
```

`ticket.key` (max. 64 Zeichen) und `ticket.title` sind Pflicht, `url` und
`body` optional; `ticket` lässt sich nicht mit `issue` kombinieren. Der
Schlüssel benennt den Branch (`sup-1234-…`). Alle anderen Felder des Endpunkts
gelten — siehe [REST API](REST-API.md#eine-aufgabe-starten).

### Kommentare posten

`POST /api/v1/tasks/{task}/ticket-comments` (`tasks:write`) zeigt einen
Kommentar deines Tickets als Hinweis in der Aufgabe und benachrichtigt die
Personen der Aufgabe:

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `author` | ja | Name, der beim Kommentar angezeigt wird (max. 120 Zeichen) |
| `body` | ja | Der Kommentar (angezeigt bis 2.000 Zeichen) |
| `url` | nein | Link zum Kommentar |

Antwortet mit `202` und leerem Body.

### Webhook-Events

Für jedes Event sendet Argos einen `POST` an die Webhook-URL:

- Header: `Content-Type: application/json`, `X-Argos-Event: <event>`,
  `X-Argos-Signature: sha256=<hex>`.
- Die Signatur ist der HMAC-SHA256 des **rohen Request-Bodys** mit dem
  Signatur-Geheimnis der Integration, hex-codiert, mit dem Präfix `sha256=`.
  Vergleiche sie in konstanter Zeit, bevor du der Anfrage vertraust.
- Timeout 10 Sekunden; bei einem Fehler versucht Argos es bis zu dreimal
  erneut (nach 10 s, 60 s und 5 min). Fehler werden protokolliert, nie in der
  Aufgabe angezeigt.

| `event` | Wann | Zusätzliche Felder |
|---|---|---|
| `started` | die Aufgabe wurde mit dem Ticket angelegt | — |
| `plan_waiting` | der Plan einer automatischen Aufgabe wartet auf Freigabe | `plan` |
| `pull_request` | der Pull Request wurde geöffnet | `pull_request_url` |
| `run_failed` | ein automatischer Lauf ist mit einem Fehler stehen geblieben | `detail` |
| `run_budget_exceeded` | ein automatischer Lauf hat sein Budget aufgebraucht | `detail` |
| `done` | der Pull Request wurde gemergt | — |
| `cancelled` | der Pull Request wurde ohne Merge geschlossen, oder die Aufgabe wurde abgebrochen | — |
| `owner_changed` | die Aufgabe wurde übergeben | `owner` (`name`, `email`) |

Jeder Payload hat denselben Rahmen:

```json
{
  "event": "pull_request",
  "ticket": { "key": "SUP-1234", "url": "https://desk.example.com/tickets/1234" },
  "task": {
    "id": "01J…",
    "title": "CSV export drops umlauts",
    "status": "pr_open",
    "url": "https://argos.example.com/w/workspaces/01J…?task=01J…",
    "branch": "sup-1234-csv-export-drops-umlauts",
    "pull_request": { "url": "https://github.com/acme/app/pull/7", "number": 7, "state": "open" }
  },
  "pull_request_url": "https://github.com/acme/app/pull/7",
  "sent_at": "2026-10-21T14:00:00+00:00"
}
```

`task.status` ist der Status der Aufgabe wie in der REST API (`preparing`,
`running`, `waiting`, `ready`, `pr_open`, `done`, `cancelled`, `archived`);
`task.pull_request` ist `null`, solange es keinen Pull Request gibt.

Die Signatur prüfen, z. B. in PHP:

```php
$expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
if (! hash_equals($expected, $request->header('X-Argos-Signature'))) {
    abort(401);
}
```

## Unterstützung je Provider

| | GitHub | GitLab | Linear | Eigenes System | Bitbucket |
|---|---|---|---|---|---|
| Ticket in der UI auswählen / verknüpfen / anlegen | ja | ja | ja | — (REST `ticket`) | nein |
| Rückmeldung | Kommentare | Kommentare | Kommentare | Webhook | — |
| Schließen beim Merge | `Closes #` im Pull Request | `Closes #` im Pull Request | durch Argos | Event `done` | — |
| Ticket-Kommentare in der Aufgabe | gepollt | gepollt | gepollt | REST | — |
| Zugewiesene Person folgt dem Besitz | Login des verbundenen Accounts | Login des verbundenen Accounts | E-Mail | Event `owner_changed` | — |
| Issue startet eine automatische Aufgabe | ja | ja | ja | REST | nein |

Bitbucket wird als Code-Host (Pull Requests) unterstützt, nicht als
Ticket-System.
