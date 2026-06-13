# Task-Provider-Integration (Issue-Tracker)

Argos kann ein Projekt mit einem externen Issue-Tracker verbinden. Neue Issues
werden als Argos-Tasks importiert, Argos schreibt seine Phasenergebnisse als
Kommentare zurück auf das Quell-Issue, eine autorisierte 👍-Reaktion auf den
Konzept-Kommentar startet die Implement-Phase, und — sofern du dich dafür
entscheidest — wird das Quell-Issue geschlossen, sobald der Task fertig ist.

Diese Seite richtet sich an Betreiber, die einen Issue-Tracker mit einem
Argos-Projekt verdrahten. Sie setzt voraus, dass die Provider-Verbindung
(OAuth oder ein Personal Access Token) bereits besteht; das
provider-spezifische Setup wird in den unten verlinkten Anleitungen behandelt.

## Inhalt

- [Unterstützte Provider](#supported-providers)
- [Wie die Integration funktioniert](#how-the-integration-works)
- [Voraussetzungen](#prerequisites)
- [Ein Binding erstellen](#creating-a-binding)
- [Webhook-Modus](#webhook-mode)
- [Poll-Modus](#poll-mode)
- [Issue zu Task](#issue-to-task)
- [Konzept-Rückschreibung und das Approve-Gate](#concept-write-back-and-the-approve-gate)
- [Status-Sync beim Abschluss](#status-sync-on-completion)
- [Filterung](#filtering)
- [Umgebungsvariablen](#environment-variables)
- [Fehlerbehebung](#troubleshooting)

## Unterstützte Provider

Als Task-Provider (Issue-Ingest, Rückschreibung, Approve-Gate, Status-Sync):

- **GitHub** — siehe [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (inklusive self-hosted) — siehe [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — siehe [SETUP-LINEAR.md](SETUP-LINEAR.md)

Bitbucket lässt sich als *Code*-Provider mit Argos verbinden
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), ist aber **nicht** als Task-Provider
verdrahtet — im Binding-Formular gibt es keine Bitbucket-Option, und
Webhook-Registrierung / Signaturprüfung sind dafür nicht implementiert. Erwarte
keinen Issue-Ingest aus Bitbucket.

## Wie die Integration funktioniert

Von Ende zu Ende erledigt ein Task-Provider vier Dinge:

1. **Ingest** — ein neues Issue (per Webhook empfangen oder vom Poller
   abgeholt), das den Filtern des Bindings entspricht, wird zu einem neuen
   Argos-Task.
2. **Rückschreibung** — wenn eine Argos-Phase abgeschlossen ist, schreibt Argos
   einen Kommentar auf das Quell-Issue mit dem Phasenergebnis (Konzepttext,
   Implement-Zusammenfassung oder den Pull-/Merge-Request-Link).
3. **Approve-Gate** — wenn die Konzept-Phase abgeschlossen ist, ist der
   Konzept-Kommentar die Review-Oberfläche. Eine autorisierte 👍-Reaktion auf
   diesen Kommentar startet die Implement-Phase. Reaktionen werden von den
   Providern nicht gepusht, daher pollt Argos danach.
4. **Status-Sync** (opt-in) — wenn der Argos-Task als abgeschlossen markiert
   wird, wird das Quell-Issue geschlossen/aufgelöst.

Jedes Binding gehört zu einem Projekt (Repo Profile) und zeigt auf ein externes
Projekt/Team. Die Verbindung zwischen einem importierten Task und seinem
Quell-Issue wird als *External Issue Link* festgehalten — dieser Datensatz trägt
die Task-ID, die Konzept-Kommentar-ID und den letzten Sync-Zeitstempel.

## Voraussetzungen

- Eine Provider-Verbindung für den Provider des Projekts — entweder ein
  **Connected Account** (OAuth) oder ein gespeicherter **Access Token (PAT)**.
  Siehe die Provider-Anleitung: [SETUP-GITHUB.md](SETUP-GITHUB.md),
  [SETUP-GITLAB.md](SETUP-GITLAB.md) oder [SETUP-LINEAR.md](SETUP-LINEAR.md).
- Das Projekt (**Repo Profile**) muss in Argos bereits existieren.
- Der Scheduler muss für den Poll-Modus und das Approve-Gate laufen — sowohl
  das Issue-Polling als auch die Konzept-Freigabe-Prüfung laufen über Laravels
  Schedule (siehe [Poll-Modus](#poll-mode)).

Die OAuth-Scopes, die du für GitHub (`repo`) und GitLab (`api`) ohnehin
vergibst, decken die Webhook-Verwaltung ab, sodass für den Webhook-Modus kein
zusätzlicher Scope nötig ist. Für Linear erfordert das Registrieren eines
Webhooks ein Organisationsmitglied mit ausreichenden Berechtigungen; siehe
[SETUP-LINEAR.md](SETUP-LINEAR.md).

## Ein Binding erstellen

1. Öffne im Admin-Panel das Projekt unter **Repo Profiles**.
2. Öffne den Tab **Task-Provider** → **Create**.
3. Fülle die Felder aus:

| Feld | Beschreibung |
|---|---|
| **Provider** | GitHub, GitLab oder Linear |
| **Modus** (Mode) | `Webhook (Push)`, `Polling` oder `Deaktiviert` (Disabled) |
| **Zugang** (Credential) | Das OAuth-Konto **oder** der gespeicherte Access Token (PAT) für diesen Provider |
| **Projekt / Team** | Das externe Projekt/Team. Wird automatisch aus dem gewählten Credential geladen. GitHub/GitLab: ein Repository (`owner/repo`); Linear: ein Team |
| **Labels-Filter** | Optional: Nur Issues, die mindestens eines dieser Labels tragen, werden importiert |
| **Issue schließen bei Task-Abschluss** | Optionaler Schalter: das Quell-Issue schließen, wenn der Argos-Task abgeschlossen wird ([Status-Sync](#status-sync-on-completion)) |

4. Speichern → das Binding wird im Status **Pending** angelegt.
5. Führe die Aktion **Einrichten** (Setup) in der Binding-Zeile aus → das
   Binding wird aktiviert (Status **Active**).

Die Setup-Aktion verhält sich je nach Modus unterschiedlich:

- **Polling** — markiert das Binding sofort als **Active**; kein
  Provider-API-Aufruf.
- **Webhook (Push)** — erzeugt ein Webhook-Secret, registriert den Webhook beim
  Provider, speichert die zurückgegebene Webhook-ID + das Secret und markiert
  das Binding als **Active**. Schlägt die Registrierung fehl, wird der Fehler in
  **Letzter Fehler** (Last error) festgehalten und das Binding bleibt Pending.
- **Deaktiviert** — setzt das Binding zurück auf Pending und löscht eine etwaige
  gespeicherte Webhook-ID/-Secret.

> Hinweis: Die Beschriftungen des Binding-Formulars im Admin-Panel sind derzeit
> auf Deutsch (Provider, Modus, Zugang, Projekt / Team, Labels-Filter). Die
> Provider-Werte entsprechen GitHub / GitLab / Linear.

## Webhook-Modus

Der Webhook-Modus liefert Issues in Echtzeit. Der eingehende Endpunkt ist eine
einzelne, session-lose Route; die Anfrage wird über die Signatur des Providers
authentifiziert, geprüft im Controller:

```
POST  ${APP_URL}/webhooks/issues/{provider}/{binding-id}
```

`{provider}` ist `github`, `gitlab` oder `linear`; `{binding-id}` ist die ID des
Bindings. `APP_URL` muss öffentlich erreichbar sein, damit der Provider
ausliefern kann.

Für **GitHub** und **GitLab** registriert die Setup-Aktion den Webhook
automatisch über die API für dich — normalerweise fügst du ihn nicht manuell
hinzu. Für **Linear** registriert Setup automatisch einen
Organisations-Webhook für `Issue`-Ressourcen; in der Linear-UI ist kein
manueller Schritt nötig.

Wenn du den Webhook von Hand registrieren musst (z. B. weil eine Org-Policy die
API-Registrierung blockiert), verwende diese Werte. Das Secret ist das von Setup
erzeugte und am Binding gespeicherte.

**GitHub** — Repository → Settings → Webhooks → Add webhook:

- Payload URL: `${APP_URL}/webhooks/issues/github/<binding-id>`
- Content type: `application/json`
- Secret: das erzeugte Secret (verifiziert über den `X-Hub-Signature-256`
  HMAC-SHA256-Header)
- Events: **Issues** (Argos akzeptiert auch `issue_comment`; andere
  Event-Typen werden bestätigt und ignoriert, damit GitHub keinen Retry macht)

**GitLab** — Project → Settings → Webhooks:

- URL: `${APP_URL}/webhooks/issues/gitlab/<binding-id>`
- Secret token: das erzeugte Secret (zurückgesendet und abgeglichen gegen den
  `X-Gitlab-Token`-Header)
- Trigger: **Issues events**

**Linear** — wird während Setup automatisch registriert; kein manueller Schritt
nötig:

- URL: `${APP_URL}/webhooks/issues/linear/<binding-id>`
- Signatur: roher HMAC-SHA256-Hex-Digest im `Linear-Signature`-Header

Auslieferungen werden anhand der Delivery-ID des Providers
(`X-GitHub-Delivery` / `X-Gitlab-Event-UUID` / `Linear-Delivery`) für 24 h
dedupliziert, sodass ein Provider-Retry keinen doppelten Task erzeugt.

## Poll-Modus

Im Poll-Modus holt Argos Issues nach einem Zeitplan ab, statt Pushes zu
empfangen. Im externen System wird kein Webhook registriert, und `APP_URL` muss
nicht öffentlich erreichbar sein.

Der Scheduler stößt für jedes **aktive Binding im Poll-Modus** über den Befehl
`argos:poll-issues` einen Poll-Job an. Das Intervall ist über
`ARGOS_POLL_INTERVAL_MINUTES` konfigurierbar (Standard **5**, begrenzt auf
**1–59**). Setze es lokal auf `1` für schnelles Feedback.

Dasselbe Intervall steuert auch die Konzept-Freigabe-Prüfung
(`argos:check-concept-approvals`), denn Reaktionen werden nie gepusht — siehe
[das Approve-Gate](#concept-write-back-and-the-approve-gate).

Manuelle Auslöser:

```bash
php artisan argos:poll-issues
php artisan argos:check-concept-approvals
```

## Issue zu Task

Wenn ein passendes Issue zum ersten Mal gesehen wird, legt Argos einen Task auf
dem Projekt des Bindings an:

- Der **Name** des Tasks ist der Issue-Titel; die **Beschreibung** ist der
  Issue-Body.
- Wenn das Projekt (Repo Profile) **Auto-Concept** aktiviert hat, startet die
  Konzept-Phase für den neuen Task automatisch; andernfalls wartet der Task auf
  einen manuellen Start.

Der Import erfolgt „einmalig, für immer" pro Issue: Der External Issue Link
hält fest, dass das Issue importiert wurde. Ein Issue, das zuerst *nicht*
passend gesehen und erst später gelabelt wird, importiert bei einem späteren
Poll/einer späteren Auslieferung trotzdem, aber ein importierter Task, den du in
Argos *löschst*, wird **nicht** stillschweigend erneut importiert.

## Konzept-Rückschreibung und das Approve-Gate

Nach Abschluss jeder Phase schreibt Argos einen Kommentar auf das verknüpfte
Issue. Der Kommentar trägt eine Überschrift plus das Phasenergebnis:

- **concept** — der vollständige Konzepttext (gekappt unter die
  Kommentar-Größenlimits des Providers), zur Review direkt am Issue.
- **implement** — die Ergebniszusammenfassung (nicht-technischer +
  technischer Abschnitt).
- **push** — der Pull-/Merge-Request-Link.

Jeder Kommentar endet mit einem Link zurück nach Argos. Schlägt das Posten fehl
(z. B. ein abgelaufener Token), wird der Fehler protokolliert und der Workflow
fährt fort.

> Der Kommentartext wird derzeit auf Deutsch gerendert, zum Beispiel:
> `**Argos** — Phase **Concept** abgeschlossen mit Status: **Completed**`.

**Approve-Gate.** Wenn der Konzept-Kommentar gepostet wird, speichert Argos
dessen Kommentar-ID. Während der Task in **Concept Review** ist, pollt die
Konzept-Freigabe-Prüfung diesen Kommentar auf Reaktionen. Findet sie ein 👍
(`+1` / `thumbsup` / `👍`) von einem Benutzer, der **zur Freigabe autorisiert**
ist, startet sie die Implement-Phase.

„Autorisiert" ist provider-spezifisch:

- **GitHub / GitLab** — der reagierende Benutzer hat Write-/Admin-Zugriff auf
  das Repository.
- **Linear** — ein aktives Organisationsmitglied, das kein Gast ist.

Das Gate ist idempotent: Der Start von Implement bewegt den Task aus Concept
Review heraus, sodass ein zweites 👍 ein No-op ist. Die Prüfung läuft auf
demselben Zeitplan wie das Polling (`ARGOS_POLL_INTERVAL_MINUTES`); sie
erfordert, dass der Scheduler auch im Webhook-Modus läuft, da Provider keine
Reaktions-Events pushen.

## Status-Sync beim Abschluss

Der Status-Sync ist **opt-in pro Binding** über den Schalter **Issue schließen
bei Task-Abschluss** (das Filter-Flag `close_on_complete`). Wenn aktiviert und
der Argos-Task als abgeschlossen markiert wird, tut Argos:

1. Postet einen Abschluss-Kommentar mit dem Pull-Request-Link (falls vorhanden),
   dann
2. Schließt/löst das Quell-Issue auf.

Dies ist Best-Effort: Ein Provider-Fehler wird protokolliert und blockiert nie
den Abschluss des Tasks in Argos. Mit ausgeschaltetem Schalter bleibt der Ingest
rein eingehend und das Quell-Issue wird unangetastet gelassen.

## Filterung

Die Filterung wird vor dem Ingest angewendet:

- **Labels-Filter** — ODER-Semantik: Ein Issue wird nur importiert, wenn es
  mindestens eines der konfigurierten Labels trägt. Sind keine Labels
  konfiguriert, passieren alle Issues den Label-Filter.
- Ein Binding kann auch nach dem **Status** des Issues filtern (z. B. nur
  offene Issues), gespeichert neben den übrigen Filtern.

Ein Issue, das die Filter nicht passiert, erhält trotzdem einen External Issue
Link (damit sein zuletzt gesehener Zustand verfolgt wird), aber es wird kein
Task erstellt.

## Umgebungsvariablen

Für das Binding selbst werden keine Provider-Secrets über env konfiguriert — das
Credential kommt aus dem gewählten OAuth-Konto oder PAT. Die relevanten
Variablen sind:

```env
# Base URL — also the base of the inbound webhook URL.
# Must be publicly reachable when webhook mode is used.
APP_URL=https://argos.example.com

# How often (minutes) the scheduler polls issue providers and checks
# concept-comment reactions. Default 5; clamped to 1–59. Set to 1 locally.
ARGOS_POLL_INTERVAL_MINUTES=5
```

Der Scheduler (`php artisan schedule:work` oder ein System-Cron-Eintrag, der
`schedule:run` ausführt) muss aktiv sein, damit der Poll-Modus und das
Approve-Gate funktionieren.

## Fehlerbehebung

- **Setup schlägt fehl / Binding bleibt Pending** — lies **Letzter Fehler**
  (Last error) in der Binding-Zeile. Häufige Ursachen: dem Credential fehlt die
  Webhook-Berechtigung, oder `APP_URL` ist für den Provider zur Validierung
  nicht erreichbar.
- **Im Poll-Modus erscheinen keine Tasks** — stelle sicher, dass der Scheduler
  läuft und das Binding **Active** ist; prüfe, dass der **Labels-Filter** die
  Issues nicht ausschließt. Die Spalte **Letzter Poll** (Last poll) zeigt, wann
  das Binding zuletzt abgeholt wurde.
- **Webhook-Auslieferungen abgelehnt (401)** — Signatur/Secret stimmen nicht
  überein. Führe **Einrichten** erneut aus, um das Secret neu zu erzeugen und
  erneut zu registrieren, oder kopiere bei einem manuellen Webhook das exakt
  gespeicherte Secret in den Provider.
- **👍 startet Implement nicht** — der Task muss in **Concept Review** sein, die
  Reaktion muss 👍 sein, der reagierende Benutzer muss autorisiert sein
  (Repo-Write/-Admin oder Linear-Mitglied ohne Gast-Status), und der Scheduler
  muss die Freigabe-Prüfung ausführen.
