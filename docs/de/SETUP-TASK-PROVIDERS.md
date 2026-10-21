# Task-Provider-Integration (Issue-Tracker)

Argos kann ein Projekt mit einem externen Issue-Tracker verbinden. Ein neues
passendes Issue startet einen **automatischen Lauf** in einem Workspace
([Auto-Threads](WORKSPACES.md#auto-threads)): Claude plant, setzt um, behebt
fehlschlagende Quality-Gates und öffnet einen Pull Request, der das Issue beim
Merge schließt. Argos berichtet unterwegs am Issue, und ein autorisiertes 👍 auf
den Plan-Kommentar gibt den Plan frei.

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
- [Issue zu automatischem Lauf](#issue-zu-automatischem-lauf)
- [Kommentare und Plan-Freigabe](#kommentare-und-plan-freigabe)
- [Filterung](#filtering)
- [Umgebungsvariablen](#environment-variables)
- [Fehlerbehebung](#troubleshooting)

## Unterstützte Provider

Als Issue-Trigger (Ingest, Kommentare, Plan-Freigabe):

- **GitHub** — siehe [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (inklusive self-hosted) — siehe [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — siehe [SETUP-LINEAR.md](SETUP-LINEAR.md)

Bitbucket lässt sich als *Code*-Provider mit Argos verbinden
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), ist aber **nicht** als Task-Provider
verdrahtet — im Binding-Formular gibt es keine Bitbucket-Option, und
Webhook-Registrierung / Signaturprüfung sind dafür nicht implementiert. Erwarte
keinen Issue-Ingest aus Bitbucket.

## Wie die Integration funktioniert

Von Ende zu Ende erledigt ein Binding drei Dinge:

1. **Ingest** — ein neues Issue (per Webhook empfangen oder vom Poller
   abgeholt), das den Filtern des Bindings entspricht, startet einen
   automatischen Lauf in einem neuen Workspace, einmal pro Issue.
2. **Kommentare** — Argos kommentiert am Issue, wenn der Lauf startet, wenn sein
   Plan auf Freigabe wartet, wenn der Pull Request geöffnet wird und wenn der
   Lauf fehlschlägt oder sein Budget erreicht.
3. **Plan-Freigabe** — mit der Plan-Einstellung *auf Freigabe warten* des
   Projekts gibt eine autorisierte 👍-Reaktion auf den Plan-Kommentar den Plan
   frei. Reaktionen werden von den Providern nicht gepusht, daher pollt Argos
   danach.

Das Issue wird durch das Mergen des Pull Requests geschlossen (`Closes #…` bei
GitHub/GitLab, die Issue-Verknüpfung bei Linear) — Argos schließt es nie von
sich aus.

Jedes Binding gehört zu einem Projekt (Repo Profile) und zeigt auf ein externes
Projekt/Team. Läufe handeln für den **Automatik-Nutzer** des Projekts (Projekt →
*Automatik*): Der Claude-Agent dieser Person erledigt die Arbeit, und sie besitzt
den Workspace. Ohne Automatik-Nutzer startet nichts, und **Letzter Fehler** des
Bindings sagt das. Die Verbindung zwischen einem Issue und seinem Workspace wird
als *External Issue Link* festgehalten.

## Voraussetzungen

- Eine Provider-Verbindung für den Provider des Projekts — entweder ein
  **Connected Account** (OAuth) oder ein gespeicherter **Access Token (PAT)**.
  Siehe die Provider-Anleitung: [SETUP-GITHUB.md](SETUP-GITHUB.md),
  [SETUP-GITLAB.md](SETUP-GITLAB.md) oder [SETUP-LINEAR.md](SETUP-LINEAR.md).
- Das Projekt (**Repo Profile**) muss in Argos bereits existieren.
- Der Scheduler muss für den Poll-Modus und die Plan-Freigabe laufen — sowohl
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
| **Issue schließen bei Task-Abschluss** | Wird nicht mehr verwendet — der Merge des Pull Requests schließt das Issue. |

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

Dasselbe Intervall steuert auch die Plan-Freigabe-Prüfung
(`argos:check-plan-approvals`), denn Reaktionen werden nie gepusht — siehe
[Plan-Freigabe](#kommentare-und-plan-freigabe).

Manuelle Auslöser:

```bash
php artisan argos:poll-issues
php artisan argos:check-plan-approvals
```

## Issue zu automatischem Lauf

Wenn ein passendes Issue zum ersten Mal gesehen wird, legt Argos einen Workspace
auf dem Projekt des Bindings an und startet darin einen automatischen Lauf:

- Der Workspace heißt `#<number> <title>`, sein Branch ist
  `ws/<number>-<title>`.
- Die Aufgabe des Laufs sind Titel, Text und Link des Issues, markiert als nicht
  vertrauenswürdige Eingabe (Claude behandelt sie als Daten, nicht als
  Anweisungen).
- Plan-Modus, Auslieferungs-Modus und Budgets kommen aus den
  **Automatik**-Einstellungen des Projekts.

Der Start erfolgt „einmalig, für immer" pro Issue: Ein Issue, das zuerst *nicht*
passend gesehen und erst später gelabelt wird, startet bei einem späteren
Poll/einer späteren Auslieferung trotzdem, aber ein Workspace, den du *löschst*,
wird **nicht** neu angelegt. Kann der Lauf nicht starten (kein nutzbarer Agent,
zu viele laufende Workspaces), versucht es der nächste Poll/die nächste
Auslieferung erneut.

## Kommentare und Plan-Freigabe

Argos kommentiert am Issue, wenn der Lauf startet (mit einem Link, um ihn live
zu verfolgen), wenn der Plan auf Freigabe wartet, wenn der Pull Request geöffnet
wird und wenn der Lauf fehlschlägt oder sein Budget aufbraucht. Schlägt das
Posten fehl (z. B. ein abgelaufener Token), wird der Fehler protokolliert und
der Lauf fährt fort.

**Plan-Freigabe.** Mit *Planen, dann auf Freigabe warten* wird der Plan als
Kommentar gepostet. Findet die Plan-Freigabe-Prüfung darauf ein 👍 (`+1` /
`thumbsup` / `👍`) von einem Benutzer, der **zur Freigabe autorisiert** ist,
fährt der Lauf fort. Du kannst auch in Argos, über die REST-API oder per MCP
freigeben.

„Autorisiert" ist provider-spezifisch:

- **GitHub / GitLab** — der reagierende Benutzer hat Write-/Admin-Zugriff auf
  das Repository.
- **Linear** — ein aktives Organisationsmitglied, das kein Gast ist.

Die Prüfung (`argos:check-plan-approvals`) läuft auf dem Poll-Zeitplan
(`ARGOS_POLL_INTERVAL_MINUTES`); sie erfordert den Scheduler auch im
Webhook-Modus, da Provider keine Reaktions-Events pushen.

## Filterung

Die Filterung wird vor dem Ingest angewendet:

- **Labels-Filter** — ODER-Semantik: Ein Issue wird nur importiert, wenn es
  mindestens eines der konfigurierten Labels trägt. Sind keine Labels
  konfiguriert, passieren alle Issues den Label-Filter.
- Ein Binding kann auch nach dem **Status** des Issues filtern (z. B. nur
  offene Issues), gespeichert neben den übrigen Filtern.

Ein Issue, das die Filter nicht passiert, erhält trotzdem einen External Issue
Link (damit sein zuletzt gesehener Zustand verfolgt wird), aber es startet kein
Lauf.

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
`schedule:run` ausführt) muss aktiv sein, damit der Poll-Modus und die
Plan-Freigabe funktionieren.

## Fehlerbehebung

- **Setup schlägt fehl / Binding bleibt Pending** — lies **Letzter Fehler**
  (Last error) in der Binding-Zeile. Häufige Ursachen: dem Credential fehlt die
  Webhook-Berechtigung, oder `APP_URL` ist für den Provider zur Validierung
  nicht erreichbar.
- **Im Poll-Modus starten keine Läufe** — stelle sicher, dass der Scheduler
  läuft, das Binding **Active** ist und das Projekt einen **Automatik-Nutzer**
  mit nutzbarem Agenten hat; prüfe, dass der **Labels-Filter** die Issues nicht
  ausschließt. Die Spalte **Letzter Poll** (Last poll) zeigt, wann
  das Binding zuletzt abgeholt wurde.
- **Webhook-Auslieferungen abgelehnt (401)** — Signatur/Secret stimmen nicht
  überein. Führe **Einrichten** erneut aus, um das Secret neu zu erzeugen und
  erneut zu registrieren, oder kopiere bei einem manuellen Webhook das exakt
  gespeicherte Secret in den Provider.
- **👍 gibt den Plan nicht frei** — der Lauf muss auf Freigabe warten
  (Plan-Einstellung *auf Freigabe warten* des Projekts), die Reaktion muss ein 👍
  auf dem Plan-Kommentar von Argos sein, der reagierende Benutzer muss
  autorisiert sein (Repo-Write/-Admin oder Linear-Mitglied ohne Gast-Status), und
  der Scheduler muss die Freigabe-Prüfung ausführen.
