# Linear Setup-Anleitung

Diese Anleitung richtet sich an alle, die **Argos betreiben** und **Linear**
als Issue-Tracker eines Workspaces anbinden möchten: Linear-Issues werden zu
Tickets von Aufgaben, passende Issues starten automatische Aufgaben, Argos
kommentiert den Fortschritt der Aufgabe am Issue und schließt es beim Merge,
und eine 👍-Reaktion gibt den Plan frei.

Sie behandelt ausschließlich die Linear-spezifischen Teile. Die gemeinsamen
Mechanismen — wie eine Integration angelegt wird, wie Webhook- und Poll-Modus
funktionieren, die Plan-Freigabe — sind einmalig in
[SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) beschrieben und hier nur
zusammengefasst. Lies diese Seite zusammen mit der vorliegenden.

## Inhalt

- [Wahl der Zugangsdaten: OAuth oder API-Key](#wahl-der-zugangsdaten-oauth-oder-api-key)
- [Variante A: OAuth-Anwendung](#variante-a-oauth-anwendung)
- [Variante B: Persönlicher API-Key](#variante-b-personlicher-api-key)
- [Integration anlegen](#integration-anlegen)
- [Webhook-Modus](#webhook-modus)
- [Poll-Modus](#poll-modus)
- [Vom Issue zur automatischen Aufgabe](#vom-issue-zur-automatischen-aufgabe)
- [Plan-Freigabe](#plan-freigabe)
- [Issue-Identifikatoren](#issue-identifikatoren)
- [Fehlerbehebung](#fehlerbehebung)

## Wahl der Zugangsdaten: OAuth oder API-Key

Argos kann sich auf zwei Arten gegenüber Linears GraphQL-API authentifizieren,
und beide lassen sich als **Zugang** einer Integration auswählen:

- **OAuth-Konto** (ein verbundenes Konto) — empfohlen für eine Instanz mit
  mehreren Personen. Das Access-Token wird über Linears OAuth-Flow bezogen und
  als `Bearer`-Token gesendet.
- **Persönlicher API-Key** (ein gespeichertes Access-Token / PAT) — ein
  `lin_api_…`-Key, der in Linear erstellt wurde. Argos erkennt das
  `lin_api_`-Präfix und sendet den Key **roh** (ohne `Bearer`-Präfix), wie
  Linear es verlangt.

Beide Arten von Zugangsdaten funktionieren im **Poll-Modus**. Für den
**Webhook-Modus** müssen die Zugangsdaten die Berechtigung haben, einen Webhook
auf dem Ziel-Team zu erstellen (siehe [Webhook-Modus](#webhook-modus)).

## Variante A: OAuth-Anwendung

### 1. Die OAuth-Anwendung in Linear registrieren

1. Gehe in Linear zu **Settings** → **API** → **OAuth applications** →
   **New application** (Deep-Link:
   `https://linear.app/settings/api/applications/new`).
2. Fülle aus:
   - **Name**: z. B. `Argos`
   - **Callback URL**: `${APP_URL}/auth/linear/callback`
     (ersetze `${APP_URL}` durch die URL deiner Argos-Instanz, z. B.
     `https://argos.example.com`)
3. Erstelle die App und kopiere dann die **Client ID** und das
   **Client Secret**.

### 2. Umgebungsvariablen konfigurieren

Füge zur `.env` des Argos-Managers hinzu:

```env
LINEAR_CLIENT_ID=<your-client-id>
LINEAR_CLIENT_SECRET=<your-client-secret>
```

Der Callback-Pfad ist fest (`config('services.linear.redirect')` ist
standardmäßig `/auth/linear/callback`); es gibt keine separate URL-Variable.
Starte den Manager neu, damit er die neuen Variablen übernimmt.

### 3. Das Konto verbinden

1. Gehe im Admin-Panel zu **Verknüpfte Accounts** (oder durchlaufe den
   Onboarding-Flow) und starte die Linear-Verbindung.
2. Argos leitet dich zu `https://linear.app/oauth/authorize` weiter, um die
   Anwendung zu autorisieren; bestätige die angeforderten Scopes.
3. Du wirst zurück zu Argos geleitet und das Linear-Konto wird als verbunden
   angezeigt.

Der OAuth-Flow fordert diese Scopes an:

```
read write issues:create comments:create admin
```

Der `admin`-Scope ist enthalten, weil die **Webhook-Verwaltung** (Erstellen und
Löschen eines Webhooks auf einem Team) in Linear Admin-Berechtigungen
benötigt. Wenn du ausschließlich den Poll-Modus nutzt, werden nur die
read/write/issues/comments-Scopes beansprucht, aber der Flow fordert immer
`admin` an, damit der Webhook-Modus ohne erneute Verbindung funktioniert.

## Variante B: Persönlicher API-Key

Nutze diese Variante, wenn du keine OAuth-App registrieren möchtest, oder für
eine Instanz, die nur eine Person betreibt.

1. Gehe in Linear zu **Settings** → **API** → **Personal API keys**
   (`https://linear.app/settings/api`) und erstelle einen Key. Er beginnt mit
   `lin_api_`.
2. Öffne im Argos-Admin-Panel **Access-Tokens** (Provider-Zugangsdaten) →
   erstelle Zugangsdaten mit **Plattform** = `Linear` und füge den Key ein. Die
   Inline-Hilfe nennt als vorgeschlagenen Scope `read, write`.

Ein persönlicher API-Key erbt die Berechtigungen der Person, die ihn erstellt
hat. Kommentare posten und Issues lesen benötigt read/write; **einen Webhook
erstellen** erfordert, dass diese Person Admin auf dem Ziel-Team ist. Ist die
Person, der der Key gehört, kein Team-Admin, schlägt die Webhook-Registrierung
während des Setups fehl — nutze stattdessen OAuth (mit dem `admin`-Scope) oder
den Poll-Modus.

## Integration anlegen

Integrationen werden im Workspace unter **Einstellungen → Integrationen →
Issue-Tracker** angelegt. Die vollständige, provider-unabhängige
Vorgehensweise findet sich in
[SETUP-TASK-PROVIDERS.md#creating-an-integration](SETUP-TASK-PROVIDERS.md#eine-integration-anlegen).
Die Linear-spezifischen Werte:

| Feld | Wert für Linear |
|---|---|
| **Provider** | `Linear` |
| **Modus** | *Webhook (Push)*, *Polling* oder *Deaktiviert* |
| **Zugang** | Das Linear-OAuth-Konto **oder** der Linear-API-Key (PAT) |
| **Projekt / Team** | Ein Linear-**Team**, ausgewählt aus einer Liste, die anhand des Zugangs geladen wird (Team-Key + Name, z. B. `ENG — Engineering`). Gespeichert wird der Team-Key, kein `owner/repo`-Pfad |
| **Labels-Filter** | Optional: nur Issues, die mindestens eines dieser Labels tragen, starten eine Aufgabe |
| **Ticket beim Merge schließen** | An (Standard): Argos verschiebt das Issue in den *completed*-Workflow-Status des Teams, sobald die Aufgabe erledigt ist |

Die Liste **Projekt / Team** wird gefüllt, indem Linears `teams`-API mit dem
gewählten Zugang abgefragt wird — wähle also zuerst **Provider** und
**Zugang**. Den Team-Key zeigt Linear auch unter **Settings** → **Teams** an
(z. B. `ENG`).

Führe nach dem Speichern **Einrichten** in der Zeile der Integration aus. Im
Poll-Modus markiert das die Integration lediglich als Aktiv; im Webhook-Modus
registriert es den Webhook (siehe unten). Bei einem Fehler wird dieser unter
**Letzter Fehler** angezeigt und die Integration bleibt Ausstehend.

## Webhook-Modus

Im Webhook-Modus registriert **Einrichten** automatisch einen Webhook auf dem
ausgewählten Linear-**Team** — es gibt keinen manuellen Schritt in der
Linear-UI. Der Webhook ist auf das Team hinter dem gewählten
**Projekt / Team** beschränkt und abonniert ausschließlich den Ressourcentyp
`Issue`; Kommentar- und Projekt-Events werden ignoriert.

Der Inbound-Endpunkt (für alle Provider gemeinsam) ist:

```
POST  ${APP_URL}/webhooks/issues/linear/<integration-id>
```

`APP_URL` muss öffentlich erreichbar sein, damit Linear ausliefern kann. Argos
erzeugt während des Setups ein Webhook-Secret und registriert es mit dem
Webhook. Linear signiert jede Auslieferung mit **HMAC-SHA256** über den rohen
Request-Body und sendet sie als **rohen Hex-Digest** im `Linear-Signature`-Header
(ohne `sha256=`-Präfix). Der Controller verifiziert sie mit `hash_equals`; eine
Abweichung oder ein fehlendes Secret führt zu `401`.

Auslieferungen werden 24 Stunden lang anhand des `Linear-Delivery`-Headers
dedupliziert, sodass ein Linear-Retry keine zweite Aufgabe startet.

**Voraussetzungen für den Webhook-Modus:**

- `APP_URL` von Linears Servern aus öffentlich erreichbar.
- Zugangsdaten, die einen Webhook auf dem Team erstellen dürfen: ein
  OAuth-Konto mit dem `admin`-Scope oder ein API-Key, dessen Person Team-Admin
  ist.

Wenn eine Org-Richtlinie die API-gesteuerte Webhook-Registrierung blockiert,
gibt es für Linear keinen unterstützten manuellen Fallback (das Secret wird
serverseitig erzeugt und nie angezeigt); nutze stattdessen den Poll-Modus.

## Poll-Modus

Im Poll-Modus ruft Argos die Issues des Teams nach einem Zeitplan ab, statt
Pushes zu empfangen. In Linear wird kein Webhook registriert und `APP_URL` muss
nicht öffentlich erreichbar sein. **Einrichten** markiert die Integration
sofort als Aktiv.

Der Poll läuft über den geplanten Befehl `argos:poll-issues`. Das Intervall ist
über `ARGOS_POLL_INTERVAL_MINUTES` konfigurierbar (Standard **5**, begrenzt auf
**1–59**); setze es lokal auf `1` für schnelleres Feedback. Dasselbe Intervall
steuert auch die Plan-Freigabe-Prüfung (`argos:check-plan-approvals`) und die
Ticket-Kommentare (`argos:tasks:sync-ticket-comments`). Der Scheduler
(`php artisan schedule:work` oder ein System-Cron, der `schedule:run`
ausführt) muss für den Poll-Modus *und* für die Plan-Freigabe aktiv sein —
siehe [SETUP-TASK-PROVIDERS.md#poll-mode](SETUP-TASK-PROVIDERS.md#poll-modus).

## Vom Issue zur automatischen Aufgabe

Wenn ein passendes Linear-Issue zum ersten Mal gesehen wird, startet Argos eine
automatische Aufgabe im Workspace der Integration. Sie gehört der Person, der
das Issue zugewiesen ist (abgeglichen per E-Mail), wenn diese in ihrem Profil
zugestimmt hat, andernfalls dem Automatik-Nutzer des Workspaces, wobei die
zugewiesene Person eingeladen wird. Der Branch trägt den Issue-Key (z. B.
`eng-118-…`). Der Start erfolgt einmalig pro Issue. Siehe
[SETUP-TASK-PROVIDERS.md#issue-to-automatic-task](SETUP-TASK-PROVIDERS.md#vom-issue-zur-automatischen-aufgabe)
und [Tickets](TICKETS.md).

## Plan-Freigabe

Mit der Plan-Einstellung *auf Freigabe warten* des Workspaces postet Argos den
Plan als Kommentar auf das Linear-Issue. Eine 👍-Reaktion von einer
**berechtigten** Person gibt ihn frei, und die Aufgabe läuft weiter.

Für Linear bedeutet „berechtigt zur Freigabe“ ein **aktives
Organisationsmitglied, das kein Gast ist**: Die reagierende Person muss in
Linear `active = true` und `guest != true` haben. (Linear kennt keine
Per-Repo-Berechtigungen, daher steht diese Org-Mitglied-Prüfung stellvertretend
für den write-/admin-Zugriff, der bei GitHub/GitLab verwendet wird. Admins sind
eine Obermenge ohne Gäste und qualifizieren sich.)

Reaktionen werden von Linear nie gepusht, daher verlässt sich die Freigabe
selbst im Webhook-Modus auf den geplanten `argos:check-plan-approvals`-Lauf.
Vollständige Mechanik:
[SETUP-TASK-PROVIDERS.md#comments-and-plan-approval](SETUP-TASK-PROVIDERS.md#kommentare-und-plan-freigabe).

Der Pull Request verknüpft das Linear-Issue. Ist die Aufgabe erledigt (Pull
Request gemergt) und *Ticket beim Merge schließen* aktiv, verschiebt Argos das
Issue in den ersten *completed*-Workflow-Status des Teams; bei einer Übergabe
wird die neue Person, der die Aufgabe gehört (per E-Mail), dem Issue
zugewiesen.

## Issue-Identifikatoren

Linear identifiziert Issues per **UUID**. Argos speichert diese UUID als
`external_id` auf dem External Issue Link und verwendet sie für alle
nachfolgenden API-Aufrufe (Kommentar-Rückschrieb, Reaktions-Polling). Das
geschieht transparent — keine Konfiguration erforderlich.

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| „Linear team not found for key: …“ während Einrichten | Der Team-Key hinter **Projekt / Team** ist falsch oder die Zugangsdaten können dieses Team nicht sehen — wähle es erneut aus der geladenen Liste aus oder prüfe den Key unter Linear Settings → Teams |
| OAuth-Redirect schlägt fehl / „Invalid OAuth state“ | Die Callback-URL der Linear-OAuth-App muss exakt mit `${APP_URL}/auth/linear/callback` übereinstimmen; ein nicht passender oder abgelaufener Session-State löst dies ebenfalls aus |
| Setup schlägt im Webhook-Modus fehl | Den Zugangsdaten fehlt die Berechtigung, einen Webhook auf dem Team zu erstellen — nutze ein OAuth-Konto mit dem `admin`-Scope oder einen API-Key, dessen Person Team-Admin ist. Der genaue Fehler steht unter **Letzter Fehler** |
| Webhook-Auslieferungen abgelehnt (401) | Signatur-/Secret-Abweichung oder kein Secret gespeichert. Führe **Einrichten** erneut aus, um das Secret neu zu erzeugen und zu registrieren |
| Im Poll-Modus startet keine Aufgabe | Der Scheduler läuft nicht, die Integration ist nicht **Aktiv**, der Workspace hat keinen **Automatik-Nutzer** mit nutzbarem Agenten (und die zugewiesene Person hat nicht zugestimmt), oder der **Labels-Filter** schließt die Issues aus. Prüfe die Spalten **Letzter Poll** und **Letzter Fehler** |
| 👍 gibt den Plan nicht frei | Die Aufgabe muss auf die Plan-Freigabe warten, die Reaktion muss 👍 auf Argos' Plan-Kommentar sein, die reagierende Person muss ein aktives Linear-Mitglied (kein Gast) sein und der Scheduler muss die Freigabe-Prüfung ausführen |
| `400` / `401` bei API-Aufrufen | Ein `lin_api_…`-Key, der mit einem `Bearer`-Präfix gesendet wird, wird von Linear abgelehnt — Argos behandelt dies automatisch, aber auch ein widerrufenes/abgelaufenes Token schlägt fehl. Verbinde das OAuth-Konto neu oder erstelle den API-Key neu |
