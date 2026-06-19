# OAuth – Überblick

Argos unterstützt zwei Wege, sich gegenüber deinem Git-Host zu authentifizieren:

1. **Personal Access Token (PAT)** — füge pro Projekt einen Token ein. Funktioniert
   sofort, ohne serverseitige Konfiguration.
2. **OAuth** — registriere eine OAuth-App beim Provider, trage deren Client-ID/Secret
   in der Argos-Oberfläche ein (Konfiguration → OAuth Apps) und lass Nutzer ihre
   eigenen Accounts verbinden.

## Welche Variante sollte ich wählen?

| Situation | Empfehlung |
|---|---|
| Argos einfach nur ausprobieren, einzelner Nutzer | **PAT** |
| Einzelentwickler, ein Git-Host | **PAT** |
| Mehrere Nutzer, jeweils mit eigenem Git-Account | **OAuth** |
| Du möchtest Repo-/Branch-Dropdowns im Projektformular | **OAuth** |
| Selbst gehostetes GitLab mit vielen Repos | **OAuth** |
| Air-Gapped oder keine vom Provider erreichbare öffentliche Callback-URL | **PAT** |

Du kannst beides kombinieren: Konfiguriere OAuth für einen Provider und nutze PATs für
einen anderen. Jedes Projekt wählt seine eigene Authentifizierungsmethode bei der
Erstellung.

## Was OAuth dir bringt

- **Repository-Dropdown** — wähle aus einer Liste, statt URLs einzufügen.
- **Branch-Dropdown** — sieh alle Branches des ausgewählten Repos.
- **Accounts pro Nutzer** — jeder Nutzer in Argos verbindet seinen eigenen
  Provider-Account. Tokens gelten pro Nutzer, nicht gemeinsam.
- **Automatischer Default-Branch** — Argos liest den Default-Branch des Repos vom
  Provider, sobald du es auswählst.

PAT-Projekte funktionieren weiterhin neben OAuth-Projekten — der Wechsel erfolgt pro Projekt.

## Was OAuth dich kostet

- Einmalige providerseitige Einrichtung (OAuth-App-/Consumer-Registrierung).
- Eine öffentliche Callback-URL: Der Provider muss Nutzer zurück nach
  `${APP_URL}/auth/<provider>/callback` umleiten können. Wenn deine Argos-Instanz rein
  intern ist, funktioniert OAuth nicht — nutze PAT.

## Provider-spezifische Anleitungen

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md) — unterstützt selbst gehostete Instanzen
- [Bitbucket Setup](SETUP-BITBUCKET.md) — Bitbucket Cloud

Alle drei sind Code-Provider (der Git-Host, gegen den Argos klont, Branches anlegt und
Pull Requests öffnet). GitLab erlaubt es zusätzlich, eine OAuth-App auf eine
selbst gehostete Instanz zu richten: Setze die Instanz-URL an der App selbst (siehe unten).

## Die OAuth-App in Argos registrieren

OAuth-Apps werden **in der Oberfläche** verwaltet — es gibt keine `*_CLIENT_ID` /
`*_CLIENT_SECRET` Umgebungsvariablen. Nachdem du die OAuth-App auf der
Provider-Seite erstellt hast:

1. Öffne **Konfiguration → OAuth Apps** in der Argos-Administration.
2. Füge eine App für den Provider hinzu, trage deren **Client-ID** und **Client-Secret** ein
   und aktiviere sie. Für selbst gehostetes GitLab setze die Instanz-URL an der App
   selbst (keine `GITLAB_INSTANCE_URL` Umgebungsvariable nötig).
3. Die Callback-URL ist fest auf `${APP_URL}/auth/<provider>/callback` —
   registriere genau diese URL in der OAuth-App des Providers. Das Argos-Formular zeigt
   den exakten Wert zum Kopieren an, sobald du einen Provider auswählst.

Die Zugangsdaten werden in der Datenbank gespeichert (`provider_oauth_configs`) und sind
ohne Neustart wirksam. Siehe [Configuration Reference](CONFIGURATION.md) für
Umgebungsvariablen, die *weiterhin* ENV-basiert sind.

## Nachdem OAuth konfiguriert ist

Die Seite **Connected Accounts** in der Argos-Navigation zeigt für jeden konfigurierten
Provider eine „Connect"-Schaltfläche. Sobald die Verbindung besteht, erhält das Feld
**Authentication** im Projektformular eine „OAuth"-Option, die Repos und
Branches aus dem verbundenen Account zieht.

## Token-Aktualisierung

Bitbucket und GitLab stellen kurzlebige Access-Tokens aus (~2 h); GitHub-OAuth-Apps
mit aktivierter Token-Ablaufzeit verhalten sich genauso. Argos aktualisiert den
Access-Token über den gespeicherten `refresh_token`, sobald ein verbundener Account
genutzt wird, um Worker-Zugangsdaten aufzubauen, und der Token weniger als 1 h Gültigkeit
übrig hat (`TokenRefresher::REFRESH_BUFFER_SECONDS`, bemessen, um das 3600s-
Job-Timeout des Workers abzudecken), sodass ein frisch gestarteter Job immer mit einem
Token beginnt, der den Lauf überdauert.

Wenn eine Aktualisierung fehlschlägt (widerrufener Token, Provider-4xx, fehlender
`refresh_token`), schlägt die Phase sofort fehl mit einer Meldung, die dich auffordert,
den Account neu zu verbinden. (Die UI-Meldung wird derzeit auf Deutsch angezeigt —
„… bitte Account neu verbinden".) Verbinde den Account auf der Seite
**Connected Accounts** neu, um ein frisches Paar aus Token und Refresh-Token zu erzeugen,
und führe die Aufgabe dann erneut aus.
