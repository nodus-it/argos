# Issue-Trigger

Ein Workspace kann mit einem Issue-Tracker verbunden werden. Seine Issues
lassen sich dann als [Tickets](TICKETS.md) von Aufgaben auswählen, und ein
neues passendes Issue startet von selbst eine **automatische Aufgabe** im
Workspace: Claude plant, setzt um, behebt fehlschlagende Quality-Gates und
öffnet einen Pull Request. Argos berichtet unterwegs am Issue, schließt es beim
Merge, wenn die Integration das vorsieht, und ein autorisiertes 👍 auf den
Plan-Kommentar gibt den Plan frei.

Diese Seite richtet sich an Workspace-Admins, die einen Tracker mit einem
Workspace verdrahten. Sie setzt voraus, dass die Provider-Verbindung (OAuth
oder ein Personal Access Token) bereits besteht; siehe die Provider-Anleitungen
unten. Eigene Ticket-Systeme (REST + signierter Webhook) sind unter
[Tickets → Eigene Ticket-Systeme](TICKETS.md#eigene-ticket-systeme) beschrieben.

## Inhalt

- [Unterstützte Provider](#unterstutzte-provider)
- [Wie die Integration funktioniert](#wie-die-integration-funktioniert)
- [Voraussetzungen](#voraussetzungen)
- [Eine Integration anlegen](#eine-integration-anlegen)
- [Webhook-Modus](#webhook-modus)
- [Poll-Modus](#poll-modus)
- [Vom Issue zur automatischen Aufgabe](#vom-issue-zur-automatischen-aufgabe)
- [Kommentare und Plan-Freigabe](#kommentare-und-plan-freigabe)
- [Filterung](#filterung)
- [Umgebungsvariablen](#umgebungsvariablen)
- [Fehlerbehebung](#fehlerbehebung)

## Unterstützte Provider

- **GitHub** — siehe [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (inklusive self-hosted) — siehe [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — siehe [SETUP-LINEAR.md](SETUP-LINEAR.md)
- **Eigenes System** — Aufgaben kommen über die REST API herein, Events gehen
  als signierter Webhook hinaus; siehe [Tickets](TICKETS.md#eigene-ticket-systeme).

Bitbucket kann das Repository eines Workspaces hosten
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), steht aber **nicht** als
Issue-Tracker zur Verfügung — im Integrationsformular gibt es keine
Bitbucket-Option.

## Wie die Integration funktioniert

Eine Integration gehört zu einem Workspace und zeigt auf ein externes Projekt
(GitHub-/GitLab-Repository) oder Team (Linear). Sie erledigt vier Dinge:

1. **Tickets** — die offenen Issues lassen sich aus Aufgaben heraus auswählen,
   verknüpfen und anlegen (dafür wird die erste aktive Integration des
   Workspaces genutzt).
2. **Ingest** — ein neues Issue (per Webhook empfangen oder vom Poller
   abgeholt), das den Filtern entspricht, startet eine automatische Aufgabe im
   Workspace, einmal pro Issue.
3. **Rückmeldung** — Argos kommentiert am Issue: Aufgabe gestartet, Plan
   wartet, Pull Request, Lauf fehlgeschlagen oder Budget aufgebraucht, fertig
   oder abgebrochen; neue Kommentare am Issue erscheinen in der Aufgabe. Siehe
   [Tickets](TICKETS.md#was-am-ticket-ankommt).
4. **Plan-Freigabe** — mit der Plan-Einstellung *auf Freigabe warten* gibt ein
   autorisiertes 👍 auf den Plan-Kommentar den Plan frei. Reaktionen werden von
   den Providern nicht gepusht, daher pollt Argos danach.

Die Verbindung zwischen einem Issue und seiner Aufgabe wird als *External
Issue Link* festgehalten.

## Voraussetzungen

- Eine Provider-Verbindung — ein **Connected Account** (OAuth) oder ein
  gespeicherter **Access Token (PAT)**. Siehe
  [SETUP-GITHUB.md](SETUP-GITHUB.md), [SETUP-GITLAB.md](SETUP-GITLAB.md) oder
  [SETUP-LINEAR.md](SETUP-LINEAR.md).
- Der Workspace und ein **Automatik-Nutzer** in seinen Einstellungen
  (Einstellungen → Automatik), es sei denn, jede zugewiesene Person hat
  zugestimmt, ihre Aufgaben selbst zu übernehmen (siehe
  [Vom Issue zur automatischen Aufgabe](#vom-issue-zur-automatischen-aufgabe)).
- Ein laufender Scheduler — für den Poll-Modus, die Plan-Freigabe und die
  Ticket-Kommentare.

Die OAuth-Scopes, die du für GitHub (`repo`) und GitLab (`api`) ohnehin
vergibst, decken die Webhook-Verwaltung ab. Für Linear erfordert das
Registrieren eines Webhooks ein Organisationsmitglied mit ausreichenden
Berechtigungen; siehe [SETUP-LINEAR.md](SETUP-LINEAR.md).

## Eine Integration anlegen

1. Öffne die **Einstellungen → Integrationen** des Workspaces
   (Workspace-Admins) oder den Workspace im Admin-Panel.
2. Wähle unter **Issue-Tracker** die Aktion **Erstellen**.
3. Fülle die Felder aus:

| Feld | Beschreibung |
|---|---|
| **Provider** | GitHub, GitLab, Linear oder Eigenes System |
| **Modus** | *Webhook (Push)*, *Polling* oder *Deaktiviert* |
| **Zugang** | Das OAuth-Konto **oder** der gespeicherte Access Token (PAT) für diesen Provider |
| **Projekt / Team** | Wird aus dem gewählten Zugang geladen. GitHub/GitLab: ein Repository (`owner/repo`); Linear: ein Team |
| **Labels-Filter** | Optional: Nur Issues, die mindestens eines dieser Labels tragen, starten eine Aufgabe |
| **Ticket beim Merge schließen** | Standardmäßig an; siehe [Schließen beim Merge](TICKETS.md#schliessen-beim-merge) |

4. Speichern → die Integration wird im Status **Ausstehend** angelegt.
5. Führe **Einrichten** in ihrer Zeile aus → die Integration wird **Aktiv**.

**Einrichten** je Modus:

- **Polling** — markiert die Integration als **Aktiv**; kein Provider-Aufruf.
- **Webhook (Push)** — erzeugt ein Webhook-Secret, registriert den Webhook
  beim Provider, speichert seine ID + das Secret und markiert die Integration
  als **Aktiv**. Schlägt die Registrierung fehl, steht der Fehler in **Letzter
  Fehler**, und sie bleibt Ausstehend.
- **Deaktiviert** — setzt sie zurück auf Ausstehend und löscht einen
  gespeicherten Webhook.

Binde die Integration bei GitHub und GitLab an das **eigene Repository des
Workspaces**, wenn Pull Requests dessen Issues schließen sollen (`Closes #`
bezieht sich nur auf Issues im Repository des Pull Requests).

## Webhook-Modus

Der Webhook-Modus liefert Issues in Echtzeit. Der eingehende Endpunkt ist eine
einzelne, session-lose Route; die Anfrage wird über die Signatur des Providers
authentifiziert:

```
POST  ${APP_URL}/webhooks/issues/{provider}/{integration-id}
```

`{provider}` ist `github`, `gitlab` oder `linear`. `APP_URL` muss öffentlich
erreichbar sein, damit der Provider ausliefern kann.

**Einrichten** registriert den Webhook für dich (GitHub und GitLab pro
Repository, Linear als Organisations-Webhook für `Issue`-Ressourcen). Um ihn
von Hand zu registrieren (z. B. weil eine Org-Policy die API-Registrierung
blockiert), verwende das von **Einrichten** gespeicherte Secret:

**GitHub** — Repository → Settings → Webhooks → Add webhook:

- Payload URL: `${APP_URL}/webhooks/issues/github/<integration-id>`
- Content type: `application/json`
- Secret: das erzeugte Secret (verifiziert über `X-Hub-Signature-256`)
- Events: **Issues** (Argos akzeptiert auch `issue_comment`; andere Events
  werden bestätigt und ignoriert)

**GitLab** — Project → Settings → Webhooks:

- URL: `${APP_URL}/webhooks/issues/gitlab/<integration-id>`
- Secret token: das erzeugte Secret (abgeglichen gegen `X-Gitlab-Token`)
- Trigger: **Issues events**

**Linear** — wird automatisch registriert:

- URL: `${APP_URL}/webhooks/issues/linear/<integration-id>`
- Signatur: roher HMAC-SHA256-Hex-Digest in `Linear-Signature`

Auslieferungen werden anhand der Delivery-ID des Providers
(`X-GitHub-Delivery` / `X-Gitlab-Event-UUID` / `Linear-Delivery`) für 24 h
dedupliziert.

## Poll-Modus

Argos holt Issues stattdessen nach einem Zeitplan ab; es wird kein Webhook
registriert, und `APP_URL` muss nicht öffentlich sein. Der Scheduler pollt
jede **aktive Integration im Poll-Modus** (`argos:poll-issues`) alle
`ARGOS_POLL_INTERVAL_MINUTES` (Standard **5**, begrenzt auf **1–59**).
Dasselbe Intervall steuert die Plan-Freigabe-Prüfung
(`argos:check-plan-approvals`) und die Ticket-Kommentare
(`argos:tasks:sync-ticket-comments`).

```bash
php artisan argos:poll-issues
php artisan argos:check-plan-approvals
```

## Vom Issue zur automatischen Aufgabe

Wird ein passendes Issue zum ersten Mal gesehen, startet Argos eine
automatische Aufgabe im Workspace der Integration:

- Die Aufgabe trägt den Titel des Issues und ist mit ihm als Ticket verknüpft;
  ihr Branch folgt dem Branch-Muster des Workspaces, standardmäßig
  `<ticket key>-<title>` (z. B. `github-42-fix-login`, `eng-118-fix-login`).
- Der Prompt besteht aus Titel, Text und Link des Issues, markiert als nicht
  vertrauenswürdige Eingabe.
- Plan-Modus, Auslieferungs-Modus und Budgets kommen aus **Einstellungen →
  Automatik**.
- **Besitz:** die dem Issue zugewiesene Person, wenn sie Admin oder Mitglied
  im Workspace ist, ein nutzbares Claude-Abo hat und in ihrem Profil
  zugestimmt hat (*Mir zugewiesene Issues dürfen automatisch auf meinem Abo
  starten*); sonst der **Automatik-Nutzer** des Workspaces, und die
  zugewiesene Person wird als Mitarbeitende eingeladen. Ohne beides startet
  nichts, und **Letzter Fehler** sagt das.

Der Start erfolgt „einmalig, für immer“ pro Issue: Ein Issue, das zuerst
*nicht* passend gesehen und später gelabelt wird, startet trotzdem eine
Aufgabe, aber eine Aufgabe, die du *löschst*, wird **nicht** neu angelegt.
Kann die Aufgabe nicht starten (kein nutzbarer Agent, zu viele laufende
Workspaces), versucht es der nächste Poll oder die nächste Auslieferung
erneut.

## Kommentare und Plan-Freigabe

Argos kommentiert am Issue wie unter
[Tickets → Was am Ticket ankommt](TICKETS.md#was-am-ticket-ankommt)
beschrieben. Schlägt das Posten fehl (z. B. ein abgelaufener Token), wird der
Fehler protokolliert und die Aufgabe läuft weiter.

**Plan-Freigabe.** Mit *Planen, dann auf Freigabe warten* wird der Plan als
Kommentar gepostet. Findet die Plan-Freigabe-Prüfung darauf ein 👍 (`+1` /
`thumbsup` / `👍`) von einer Person, die **zur Freigabe autorisiert** ist,
läuft der Lauf weiter. Du kannst auch in Argos, über die REST API oder per MCP
freigeben.

- **GitHub / GitLab** — die reagierende Person hat Write-/Admin-Zugriff auf
  das Repository.
- **Linear** — ein aktives Organisationsmitglied, das kein Gast ist.

Die Prüfung braucht den Scheduler auch im Webhook-Modus, da Provider keine
Reaktions-Events pushen.

## Filterung

- **Labels-Filter** — ODER-Semantik: Ein Issue startet nur dann eine Aufgabe,
  wenn es mindestens eines der Labels trägt. Ohne Labels passieren alle
  Issues.
- Ein Filter auf den **Status** des Issues (z. B. nur offene Issues) wird
  daneben gespeichert.

Ein Issue, das die Filter nicht passiert, bekommt trotzdem einen External
Issue Link (damit sein Zustand verfolgt wird), aber es startet keine Aufgabe.
Das Auswählen von Issues als Tickets in der UI ist von den Filtern nicht
betroffen.

## Umgebungsvariablen

Das Credential kommt aus dem gewählten OAuth-Konto oder PAT. Relevant sind:

```env
# Base of the inbound webhook URL; must be public for webhook mode.
APP_URL=https://argos.example.com

# Poll interval (minutes) for issues, plan reactions and ticket comments. 1–59.
ARGOS_POLL_INTERVAL_MINUTES=5
```

Der Scheduler (`php artisan schedule:work` oder ein Cron-Eintrag, der
`schedule:run` ausführt) muss aktiv sein.

## Fehlerbehebung

- **Einrichten schlägt fehl / Integration bleibt Ausstehend** — lies
  **Letzter Fehler**. Häufige Ursachen: dem Credential fehlt die
  Webhook-Berechtigung, oder `APP_URL` ist nicht erreichbar.
- **Es starten keine Aufgaben** — prüfe, dass der Scheduler läuft
  (Poll-Modus), die Integration **Aktiv** ist, der Workspace einen
  **Automatik-Nutzer** mit nutzbarem Agenten hat (oder die zugewiesene Person
  zugestimmt hat) und der **Labels-Filter** die Issues nicht ausschließt.
  **Letzter Poll** zeigt, wann zuletzt abgeholt wurde.
- **Webhook-Auslieferungen abgelehnt (401)** — Signatur/Secret stimmen nicht
  überein. Führe **Einrichten** erneut aus oder kopiere das gespeicherte
  Secret in den Provider.
- **👍 gibt den Plan nicht frei** — die Aufgabe muss auf Freigabe warten, die
  Reaktion muss ein 👍 auf dem Plan-Kommentar von Argos sein, die reagierende
  Person muss autorisiert sein, und der Scheduler muss die Freigabe-Prüfung
  ausführen.
- **Das Issue wird beim Merge nicht geschlossen** — *Ticket beim Merge
  schließen* ist aus, oder (GitHub/GitLab) das Issue liegt in einem anderen
  Repository als der Pull Request.
