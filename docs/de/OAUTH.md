# OAuth – Überblick

Argos unterstützt zwei Wege, sich gegenüber deinem Git-Host zu authentifizieren:

1. **Personal Access Token (PAT)** — füge pro Workspace einen Token ein.
   Funktioniert sofort, ohne serverseitige Konfiguration.
2. **OAuth** — registriere eine OAuth-App beim Provider, trage deren
   Client-ID/Secret in der Argos-Oberfläche ein (Administration → OAuth-Apps) und
   lass alle Nutzenden ihre eigenen Accounts verbinden.

## Welche Variante sollte ich wählen?

| Situation | Empfehlung |
|---|---|
| Argos einfach nur ausprobieren, eine Person | **PAT** |
| Allein entwickeln, ein Git-Host | **PAT** |
| Mehrere Personen, jeweils mit eigenem Git-Account | **OAuth** |
| Du möchtest Repo-/Branch-Dropdowns im Workspace-Formular | **OAuth** |
| Selbst gehostetes GitLab mit vielen Repos | **OAuth** |
| Air-Gapped oder keine vom Provider erreichbare öffentliche Callback-URL | **PAT** |

Du kannst beides kombinieren: Konfiguriere OAuth für einen Provider und nutze
PATs für einen anderen. Jeder Workspace wählt seine eigene
Authentifizierungsmethode bei der Erstellung.

## Was OAuth dir bringt

- **Repository-Dropdown** — wähle aus einer Liste, statt URLs einzufügen.
- **Branch-Dropdown** — sieh alle Branches des ausgewählten Repos.
- **Accounts pro Person** — alle Nutzenden in Argos verbinden ihren eigenen
  Provider-Account. Tokens gelten pro Person, nicht gemeinsam.
- **Automatischer Default-Branch** — Argos liest den Default-Branch des Repos
  vom Provider, sobald du es auswählst.

PAT-Workspaces funktionieren weiterhin neben OAuth-Workspaces — die Wahl
erfolgt pro Workspace. Mit OAuth kann Argos außerdem auf GitHub und GitLab die
Zuweisung eines Tickets an die neue Person übertragen, der eine Aufgabe gehört
(den Login ihres verbundenen Accounts).

## Was OAuth dich kostet

- Einmalige providerseitige Einrichtung (OAuth-App-/Consumer-Registrierung).
- Eine öffentliche Callback-URL: Der Provider muss Nutzende zurück nach
  `${APP_URL}/auth/<provider>/callback` umleiten können. Wenn deine
  Argos-Instanz rein intern ist, funktioniert OAuth nicht — nutze PAT.

## Provider-spezifische Anleitungen

- [GitHub-Einrichtung](SETUP-GITHUB.md)
- [GitLab-Einrichtung](SETUP-GITLAB.md) — unterstützt selbst gehostete Instanzen
- [Bitbucket-Einrichtung](SETUP-BITBUCKET.md) — Bitbucket Cloud

Alle drei sind Code-Provider (der Git-Host, gegen den Argos klont, Branches
anlegt und Pull Requests öffnet). GitLab erlaubt es zusätzlich, eine OAuth-App
auf eine selbst gehostete Instanz zu richten: Setze die Instanz-URL an der App
selbst (siehe unten).

## Die OAuth-App in Argos registrieren

OAuth-Apps werden **in der Oberfläche** verwaltet — es gibt keine
`*_CLIENT_ID` / `*_CLIENT_SECRET` Umgebungsvariablen. Nachdem du die OAuth-App
auf der Provider-Seite erstellt hast:

1. Öffne **Administration → OAuth-Apps** (`${APP_URL}/system/oauth-apps`).
2. Füge eine App für den Provider hinzu, trage deren **Client-ID** und
   **Client-Secret** ein und aktiviere sie. Für selbst gehostetes GitLab setze
   die Instanz-URL an der App selbst (keine `GITLAB_INSTANCE_URL`
   Umgebungsvariable nötig).
3. Die Callback-URL ist fest auf `${APP_URL}/auth/<provider>/callback` —
   registriere genau diese URL in der OAuth-App des Providers. Das
   Argos-Formular zeigt den exakten Wert zum Kopieren an, sobald du einen
   Provider auswählst.

Die Zugangsdaten werden in der Datenbank gespeichert (`provider_oauth_configs`)
und sind ohne Neustart wirksam. Siehe die
[Konfigurationsreferenz](CONFIGURATION.md) für Umgebungsvariablen, die
*weiterhin* ENV-basiert sind.

## Nachdem OAuth konfiguriert ist

Die Seite **Mein Konto → Verknüpfte Accounts** (`/account/connections`) zeigt für jeden
konfigurierten Provider eine Verbinden-Schaltfläche (z. B. „Mit GitHub
verbinden“). Sobald die Verbindung besteht, erhält das Feld
**Authentifizierung** im Workspace-Formular eine „OAuth“-Option, die Repos und
Branches aus dem verbundenen Account zieht.

## Token-Aktualisierung

Bitbucket und GitLab stellen kurzlebige Access-Tokens aus (~2 h);
GitHub-OAuth-Apps mit aktivierter Token-Ablaufzeit verhalten sich genauso.
Argos aktualisiert den Access-Token über den gespeicherten `refresh_token`,
sobald ein verbundener Account genutzt wird — etwa wenn Git in einem Workspace
seinen Repository-Zugang anfragt — und der Token weniger als 1 h Gültigkeit
übrig hat (`TokenRefresher::REFRESH_BUFFER_SECONDS`), sodass Clone, Fetch oder
Push nie mit einem Token beginnen, der gleich abläuft.

Wenn eine Aktualisierung fehlschlägt (widerrufener Token, Provider-4xx,
fehlender `refresh_token`), schlägt der Git-Vorgang (oder der Start des
Workspaces) mit einer Meldung fehl, die dich auffordert, den Account neu zu
verbinden. Verbinde den Account unter **Mein Konto → Verknüpfte Accounts** neu, um
ein frisches Paar aus Token und Refresh-Token zu erzeugen, und versuche es dann
erneut.
