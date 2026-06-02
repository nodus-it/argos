# Argos UI-Redesign — Implementierungs-Anweisung für Claude Code

> **Zweck dieses Files:** Du (Claude Code) sollst das bestehende Argos-Frontend (Laravel + Filament)
> auf das in diesem Dokument beschriebene Redesign umstellen — **1:1**. Dieses File ist die
> verbindliche Spezifikation: Design-Tokens, Komponenten, Status-/Phasen-Logik und der Aufbau der
> beiden Kern-Screens (Dashboard, Task-Detail).
>
> Der visuelle Referenz-Prototyp liegt als `Argos Redesign.html` + `*.jsx` + `styles.css` bei.
> Wo dieses Dokument und der Prototyp sich widersprechen, **gewinnt dieses Dokument**.

---

## 0. Leitlinien (nicht verhandelbar)

1. **Richtung „Warm Paper":** warmes Papier/Sand als Neutral, **Terracotta** als Akzent. Ruhig, editorial, viel Weißraum.
2. **Status nie nur über Farbe.** Jeder Status = Farbe **+** Icon **+** Text-Label (WCAG AA).
3. **Dark Mode ist gleichwertig**, kein nachträgliches Invertieren — eigene Surface-Werte (siehe §2).
4. **Eine Aktion ist primär.** Pro Screen genau **ein** Primär-Button; alles Weitere wandert ins **⋯-Menü**.
5. **Keine Verschachtelung von Navigationsebenen.** Task-Detail ist ein **chronologischer Thread**, kein Tab-in-Tab.
6. **Token-First.** Niemals Hex direkt in Komponenten — immer über die CSS-Variablen / Tailwind-Theme aus §2.
7. **Icons:** Heroicons (outline, 1.6 stroke). Keine Emojis.

---

## 1. Tech-Mapping (Laravel + Filament)

- Tokens als **Tailwind v4 `@theme`** (siehe §2.1) **und** als CSS-Custom-Properties für Filament-Overrides.
- Filament-Akzent über `FilamentColor::register([...])` mit der **Terracotta-Ramp** als `primary` (§2.3).
- Fonts via `@fontsource` oder Google Fonts (§3).
- Dark Mode: Filament unterstützt es nativ; die Surface-Werte unter `[data-theme="dark"]` (§2.2) überschreiben.
- Komponenten als **Blade-Components** (`<x-argos.badge>`, `<x-argos.phase-rail>` …) — Namen siehe §5.

---

## 2. Design-Tokens

### 2.1 Tailwind v4 `@theme` (in `resources/css/app.css`)

```css
@import "tailwindcss";

@theme {
  /* ---- Akzent · Terracotta (primary) ---- */
  --color-accent-50:  #fbf3ef;
  --color-accent-100: #f6e4da;
  --color-accent-200: #eec7b4;
  --color-accent-300: #e3a486;
  --color-accent-400: #d9805f;
  --color-accent-500: #cf6446;
  --color-accent-600: #bb5034;  /* primary (light) */
  --color-accent-700: #9a402b;  /* primary-hover (light) / accent-text */
  --color-accent-800: #7c3727;
  --color-accent-900: #682f23;
  --color-accent-950: #3a1610;

  /* ---- Neutral · warm sand ---- */
  --color-neutral-0:   #fffefb;
  --color-neutral-50:  #f7f4ee;
  --color-neutral-100: #efeae1;
  --color-neutral-200: #e3dccf;
  --color-neutral-300: #cfc5b4;
  --color-neutral-400: #a89d89;
  --color-neutral-500: #7d7565;
  --color-neutral-600: #5f584c;
  --color-neutral-700: #48433a;
  --color-neutral-800: #322e28;
  --color-neutral-900: #211e1a;
  --color-neutral-950: #161310;
  --color-neutral-975: #100d0b;

  /* ---- Semantik (shared, theme-unabhängig) ---- */
  --color-success-50:#ecfdf3; --color-success-100:#d1fadf; --color-success-400:#4ade80;
  --color-success-500:#16a34a; --color-success-600:#15803d; --color-success-700:#166534;
  --color-warning-50:#fff8eb; --color-warning-100:#fdecc8; --color-warning-400:#fbbf24;
  --color-warning-500:#f59e0b; --color-warning-600:#d97706; --color-warning-700:#b45309;
  --color-danger-50:#fef2f2;  --color-danger-100:#fde0e0;  --color-danger-400:#f87171;
  --color-danger-500:#ef4444; --color-danger-600:#dc2626;  --color-danger-700:#b91c1c;
  --color-info-50:#eff6ff;    --color-info-100:#dbeafe;    --color-info-400:#60a5fa;
  --color-info-500:#3b82f6;   --color-info-600:#2563eb;    --color-info-700:#1d4ed8;

  /* ---- Fonts ---- */
  --font-sans: "Hanken Grotesk", system-ui, sans-serif;
  --font-mono: "IBM Plex Mono", ui-monospace, monospace;

  /* ---- Radius ---- */
  --radius-sm: 9px;
  --radius:    12px;
  --radius-lg: 18px;
}
```

### 2.2 Semantische Surface-Tokens (Light + Dark)

Diese mappen die Ramps auf konkrete Rollen. In `app.css` nach dem `@theme`-Block:

```css
:root, [data-theme="light"] {
  --bg:            var(--color-neutral-50);
  --surface:       var(--color-neutral-0);
  --surface-2:     var(--color-neutral-50);
  --text:          var(--color-neutral-900);
  --text-2:        var(--color-neutral-700);
  --muted:         var(--color-neutral-500);
  --faint:         var(--color-neutral-400);
  --border:        var(--color-neutral-200);
  --border-strong: var(--color-neutral-300);
  --accent:        var(--color-accent-600);
  --accent-hover:  var(--color-accent-700);
  --accent-soft:   var(--color-accent-50);
  --accent-soft-border: var(--color-accent-200);
  --accent-text:   var(--color-accent-700);
  --on-accent:     #ffffff;
  --term-bg:       var(--color-neutral-950);
  color-scheme: light;
}

[data-theme="dark"] {
  --bg:            #100d0b;
  --surface:       #1d1a16;
  --surface-2:     #171411;
  --text:          var(--color-neutral-50);
  --text-2:        var(--color-neutral-200);
  --muted:         var(--color-neutral-400);
  --faint:         var(--color-neutral-500);
  --border:        var(--color-neutral-800);
  --border-strong: var(--color-neutral-700);
  --accent:        var(--color-accent-400);
  --accent-hover:  var(--color-accent-300);
  --accent-soft:   color-mix(in oklab, var(--color-accent-500) 18%, transparent);
  --accent-soft-border: color-mix(in oklab, var(--color-accent-500) 38%, transparent);
  --accent-text:   var(--color-accent-300);
  --on-accent:     #ffffff;
  --term-bg:       #100d0b;
  color-scheme: dark;
}
```

> **Wichtig:** Body-Hintergrund mit `transition: background-color …` (Longhand!) animieren, nicht
> `transition: background …` — sonst greift der Theme-Wechsel zur Laufzeit nicht.

### 2.3 Filament-Akzent (`AppServiceProvider::boot()`)

```php
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Colors\Color;

FilamentColor::register([
    'primary' => [
        50 => '251,243,239', 100 => '246,228,218', 200 => '238,199,180',
        300 => '227,164,134', 400 => '217,128,95',  500 => '207,100,70',
        600 => '187,80,52',   700 => '154,64,43',   800 => '124,55,39',
        900 => '104,47,35',   950 => '58,22,16',
    ],
    'gray' => Color::hex('#7d7565'), // warm sand neutral
]);
```

### 2.4 Skalen

| Token | Wert | Einsatz |
|---|---|---|
| Display | 30 / 700 | große Zahlen, Hero |
| H1 | 24 / 700 | Seitentitel |
| H2 | 19 / 600 | Abschnitte |
| H3 | 15.5 / 600 | Kartenköpfe |
| Body | 14 / 400 | Standard |
| sm | 13 | Tabellen, Meta |
| xs | 12 | Labels, Chips |
| mono | 13 | Logs, Diffs, Branches |

Spacing-Rhythmus: Karten-Padding **20px**, Grid-Gap **16px**, Tabellen-Zeile **~52px**.
Radius: Karten `--radius-lg` (18), Buttons/Inputs `--radius` (12), Chips/kleine `--radius-sm` (9).
Schatten dezent: `0 1px 2px rgba(60,40,24,.06)` (sm), `0 10px 26px rgba(60,40,24,.07)` (md).

---

## 3. Typografie / Fonts

- **Sans:** Hanken Grotesk (400/500/600/700/800).
- **Mono:** IBM Plex Mono (400/500/600/700) — für Logs, Diffs, Branch-Namen, Kosten/Tokens, **Wortmarke „ARGOS"**.
- Wortmarke: Mono, 700, `letter-spacing: .2em`, Uppercase.

---

## 4. Logo — „Lens" (final)

Auge + Terminal-Cursor. SVG, `viewBox 0 0 40 40`, alle Striche/Flächen in `var(--accent)`:

1. **Augenlinse** (mandelförmig, 2 Bögen): `M3 20S9.5 9 20 9s17 11 17 11-6.5 11-17 11S3 20 3 20Z` — stroke 2.4, kein Fill.
2. **Iris** (gefüllt): `<circle cx=20 cy=20 r=8.4 fill=accent>`.
3. **Pupillenfeld** (Surface-Farbe, schneidet die Iris aus): `<circle cx=20 cy=20 r=6 fill=var(--surface)>`.
4. **Terminal-Prompt `>`** in der Pupille: `M17.4 17 20 20l-2.6 3` — stroke 2, round.
5. **Cursor-Block**: `<rect x=21.4 y=21.6 w=2.6 h=1.9 rx=.4 fill=accent>`.

Da die Pupille `--surface` nutzt, passt sich das Logo automatisch an Light/Dark an.
Sidebar-Größe 28px, Login/große Flächen 40–46px.

---

## 5. Komponenten-Spezifikation

Jede Komponente nutzt ausschließlich die Tokens aus §2. Blade-Component-Namen in Klammern.

### 5.1 Status-Badge (`<x-argos.badge :status>`)
Pill, `border-radius:99px`, Icon (13px) + Label, 1px Border. Niemals nur Farbe.

| status | Farbe (Light bg/text/border) | Icon | Default-Label |
|---|---|---|---|
| `draft` | surface-2 / muted / border | doc | „Entwurf" |
| `running` | info-50 / info-700 / info-100 | **pulsierender Punkt** | „Läuft" |
| `waiting` | warning-50 / warning-700 / warning-100 | hand | „Wartet" |
| `success` | success-50 / success-700 / success-100 | check | „Fertig" |
| `failed` | danger-50 / danger-700 / danger-100 | x | „Fehlgeschlagen" |

Dark: Hintergrund `color-mix(in oklab, <sem>-500 16%, transparent)`, Text `<sem>-400`, Border `…34%`.
`running`-Punkt: 7px Kreis, `animation: pulse 1.6s infinite` (opacity 1→.4, scale 1→.7).

### 5.2 Phase-Chip (`<x-argos.phase-chip>`)
Kleiner Chip mit Icon + Phasenname. Phasen & Icons:
`Entwurf`=doc · `Konzept`=bulb (Glühbirne) · `Implement`=code · `Push`=push (Upload) · `Review`=feedback (Sprechblase).
Aktiver Chip: `--accent-soft` bg, `--accent-text` text, `--accent-soft-border`.

### 5.3 Button (`<x-argos.btn :variant>`)
Padding `8px 14px`, `--radius`, font 13/600, Icon 16px, Focus-Ring `0 0 0 3px var(--accent-soft)`.
- `primary`: bg `--accent`, text `--on-accent`, hover `--accent-hover`.
- `secondary`: bg `--surface`, Border `--border-strong`, hover `--surface-2`.
- `ghost`: transparent, hover `--surface-2`.
- `success` / `danger`: entsprechende `-600/-700`.
- `sm`-Modifier: `5px 10px`, font 12.

### 5.4 Aktionsmenü / Kebab (`<x-argos.action-menu>`)
Icon-Button (drei Punkte vertikal). Dropdown: `--surface`, Border, `--shadow-md`, 6px Padding.
Items: Icon 16px + Label, hover `--surface-2`. Trenner als 1px-Linie. `danger`-Item rot.
**Regel:** Pro Screen nur EIN Primär-Button sichtbar; der Rest hier rein.

### 5.5 Phasen-Leiste / Rail (`<x-argos.phase-rail :rail>`)
Horizontale Fortschrittsanzeige über die 5 Phasen `[Entwurf, Konzept, Implement, Push, Review]`.
Pro Phase ein Status: `done | active | wait | fail | todo`.
- Node: 38px Kreis, 2px Border. `done`=success-500 gefüllt + Häkchen; `active`=accent gefüllt + Phasen-Icon (+ Pulse-Ring); `wait`=warning-500 + Hand; `fail`=danger-500 + X; `todo`=outline + Phasen-Icon.
- Verbindungslinie: 2px; grün wenn vorherige Phase `done`.
- Rechts ein **Status-Hinweis**: aktuelle Phase fett + Subtext („läuft gerade" / „wartet auf dein Feedback" / „fehlgeschlagen" / „abgeschlossen").
- **Reine Anzeige, nicht klickbar.**

### 5.6 Meta-Strip (`<x-argos.meta-strip>`)
Eine Karte direkt unter dem Kopf. Erste Zeile zeigt immer: **Repository · Branch · PR (falls vorhanden) · Agent · Stack · Kosten·Tokens** — jeweils kleines Uppercase-Label + Wert (mono wo sinnvoll, Branch/PR in `--accent-text`).
Rechts ein **„Details"-Toggle** (Chevron) → klappt zweite Zeile aus: **Base Branch · Tokens · Erstellt**.
→ Alles Wichtige sofort sichtbar, Rest auf Abruf.

### 5.7 Thread-Feed (`<x-argos.thread>`) — Herzstück der Task-Detail
**Chronologisch** (ältestes oben, neuestes unten). Jeder Eintrag:
- Links eine **Timeline-Spur**: 38px Node mit Phasen-Icon (`done` = grün gefüllt), darunter durchgehende 2px-Linie.
- Rechts eine **Karte**: Kopf = Titel + rechts Meta (`Kosten` grün-mono, `Zeit` mono, `Wer`-Pill „Du"/„Claude Code"); darunter Fließtext.
- **Aufklappbare Detail-Blöcke** (wichtig — Bug-Vermeidung): Die Aktions-Buttons (`Konzept ansehen`, `Diff · N Dateien`, `Logs · N`) liegen in **einer eigenen Zeile** (`.feed-actions`). Der aufgeklappte Inhalt (Konzept / Diff / Terminal) kommt als **eigener Block darunter** (`.feed-detail`, `margin-top:14px`) — **niemals** im selben Flex-Container wie die Buttons (sonst werden sie hochgezogen).
- Diff- und Logs-Button schließen sich gegenseitig aus (nur einer offen).

Reihenfolge der Feed-Einträge entspricht dem Workflow: Task angelegt → Konzept erstellt → Implementierung → Push/PR.

### 5.8 Respond-Composer (`<x-argos.respond>`) — angedockt
**Sticky am unteren Viewport-Rand**, volle Breite, `backdrop-filter: blur(10px)`.
- Avatar + Textarea + Primär-„Senden" (disabled bis Text vorhanden).
- Darunter Schnellaktionen als Chips: **„Änderungen anfordern" · „Approve & Merge" · „Frage stellen"** (befüllen das Feld bzw. lösen die Aktion aus).
- **Wartet der Agent** (`status = waiting`): obere Border 2px `warning-500`, Hintergrund `warning-50` (dark: warning-500 @12%), Flag-Zeile mit Hand-Icon „Der Agent wartet auf dein Feedback".

### 5.9 Diff-Viewer (`<x-argos.diff>`)
Pro Datei eine Box: Kopf = Doc-Icon + Pfad (ellipsis) + rechts `+adds` (grün) / `−dels` (rot).
Zeilen: 88px Gutter (zwei Spalten alt/neu Zeilennummer, `--surface-2`, rechte Border) + Code (mono, `white-space:pre`).
`add` = success @9% bg + grüner Text; `del` = danger @9% + roter Text; `hunk` = surface-2 + accent-text.

### 5.10 Terminal / Logs (`<x-argos.terminal>`)
Dunkler Panel (`--term-bg`), Mono. Kopf: 3 Ampel-Punkte + Titel (`worker · <branch>`) + „Replay"-Button.
Body: pro Zeile `Zeilennr (faint) · Zeitstempel (grau) · Text`. Farbklassen:
`info`=#9aa3b2 · `ok`=#5ad08e · `warn`=#f5c451 · `err`=#f08a8a · `accent`=accent-hover · `cmd`=#e6e9ee bold.
Replay streamt Zeilen mit ~260ms; blinkender Cursor-Block (success-400, `blink 1.1s steps(1)`).
Zeilen-Layout: Zeilennr/Zeitstempel `flex:none; white-space:nowrap`, Text-Span `flex:1; white-space:pre-wrap; word-break:break-word` — **nicht** den ganzen Container umbrechen (sonst zerbrechen die Zeitstempel).

### 5.11 Stat-Karte (`<x-argos.stat>`) — Dashboard
Label (Icon + Text, muted) · große Mono-Zahl (34/700) · Meta-Zeile (Icon + Text).
`is-live`-Variante: linker 3px Akzent-Balken, Zahl in `--accent-text`, Border `--accent-soft-border`.
Meta-Semantik: grün (`success-600`) für „alles gut", amber (`warning-600`) für „braucht Aufmerksamkeit".

---

## 6. Screen-Aufbau

### 6.1 App-Shell
- **Sidebar (248px):** Logo oben (klickbar → Dashboard). Nav-Gruppen: *(oben)* Dashboard · **Worker** (Stacks, Agent-Credentials, Image-Builds) · **Aufgaben** (Tasks, mit Count-Badge) · **Konfiguration** (Projekte, Verknüpfte Accounts, Access-Tokens, OAuth-Apps, API-Clients, Einstellungen). Aktiver Eintrag: `--accent-soft` bg, `--accent-text`, Icon in `--accent`. Nav-Labels nie umbrechen (`white-space:nowrap`).
- Unten **Usage-Widget:** zwei Balken (Claude 5 Std. / 7 Tage); >80% → Balken `warning-500`.
- **Topbar (60px):** Breadcrumbs links; rechts Light/Dark-Toggle (Mond/Sonne), „Feedback"-Button, Avatar. `backdrop-filter: blur(10px)`.

### 6.2 Dashboard („Kontrollraum")
- 4 **Stat-Karten:** Laufende Worker · In Bearbeitung · **Wartet auf dich** (amber wenn >0) · Worker-Updates. „Wartet"/„Laufend" als `is-live` wenn >0.
- **Tasks-Tabelle** (Karte mit Suche + Filter/Spalten-Icons): Spalten Name · Projekt · Quelle · **Workflow** (Status-Badge) · **Phase** (Phase-Chip) · Agent · Letzte Aktivität. Zeile klickbar → Task-Detail, hover `--surface-2`.

### 6.3 Task-Detail (final: **Thread**) — exakter Aufbau von oben nach unten
1. **Kopf:** Breadcrumb („Tasks › Ansehen"); darunter Task-Name (H1) + großes **Status-Badge**. Rechts: **ein** kontextabhängiger Primär-Button + **⋯-Menü**.
   - Primär-Button hängt am Workflow-Stand:
     `Entwurf→„Konzept starten"` · `Konzept→„Implementieren"` · `Implement/Push→„Push & PR"` · `Review→„Abschließen"` (success) · bei `fail`→„Erneut versuchen".
   - ⋯-Menü: Demo neu aufbauen · Logs herunterladen · Konzept aktualisieren · Pull Request öffnen (falls PR) · — · **Task löschen** (danger).
2. **Phasen-Leiste** (§5.5).
3. **Meta-Strip** (§5.6).
4. **Live-Demo-Karte** (nur wenn Demo läuft): Globe-Icon + „Live-Demo" + „Live"-Badge + URL (mono, extern-Link) + „läuft ab in …".
5. **Thread-Feed** (§5.7) — chronologisch.
6. **Respond-Composer** (§5.8) — sticky unten.

---

## 7. Motion
- Status-`running`-Punkt: `pulse` (s. §5.1). Aktiver Rail-Node: weicher Pulse-Ring.
- Aktiver „arbeitet"-Block optional: diagonaler Sweep (accent @14%, 2.4s).
- Seiten-/Tab-Wechsel: `fade` (opacity 0→1, translateY 6→0, .35s).
- Alles in `@media (prefers-reduced-motion: reduce)` deaktivieren.

---

## 8. Akzeptanzkriterien (Definition of Done)
- [ ] Terracotta-Akzent + warmes Neutral überall via Tokens; kein hartkodiertes Hex in Komponenten.
- [ ] Light **und** Dark sauber; Theme-Wechsel zur Laufzeit ohne Glitch (Body-`background-color`-Transition).
- [ ] Jeder Status zeigt Farbe **+** Icon **+** Label.
- [ ] Task-Detail ist ein flacher chronologischer Thread; **keine** verschachtelten Tab-Ebenen.
- [ ] Aufgeklappte Diff/Logs/Konzept erscheinen als Block **unter** ihrer Button-Reihe, ohne Überlappung.
- [ ] Respond-Leiste klebt unten; im `waiting`-Zustand amber hervorgehoben mit Hand-Flag.
- [ ] Pro Screen genau ein Primär-Button; Rest im ⋯-Menü.
- [ ] „Lens"-Logo überall, passt sich Light/Dark an.
- [ ] Sidebar-Labels brechen nicht um; Terminal-Zeitstempel zerbrechen nicht.

---

## 9. Referenz-Dateien (im Prototyp beigelegt)
| Datei | Inhalt |
|---|---|
| `styles.css` | **Maßgebliche** Token- & Komponenten-CSS (Paper = `[data-dir="2"]`-Block). |
| `icons.jsx` | Heroicon-Pfade + `ArgosEye` (Variante „lens") + Logo. |
| `primitives.jsx` | Badge / Phase-Chip / Button / Card / Avatar / Seg. |
| `taskparts.jsx` | PhaseRail · ActionMenu · MetaStrip · Respond · Concept · Terminal · Diff. |
| `taskdetail.jsx` | Thread-Feed + Task-Detail-Container (finaler Aufbau §6.3). |
| `dashboard.jsx` | Stat-Karten + Tasks-Tabelle. |
| `shell.jsx` | Sidebar + Topbar. |
| `data.jsx` | Beispiel-Datenformen (Task-Felder, `rail`-Array, Activity-Feed). |

> Nimm `styles.css` als Quelle der Wahrheit für exakte Werte. Die `.jsx` zeigen Struktur/Markup;
> übersetze sie in Blade/Filament — die **Klassennamen und Token** bleiben gleich.
