# Argos — Design Implementation Guideline

> How to build UI in Argos so it stays consistent with the "Warm Paper +
> Terracotta" redesign. This documents the **implementation conventions** in
> the Laravel/Filament app. The visual source of truth (mockups, tokens) lives
> in `docs/design/arogs/project/argos/` (`projekt.jsx`, `styles.css`,
> `ARGOS_REDESIGN.md`). When in doubt about a value, read those.

## 1. Theme & tokens

- The whole theme lives in `resources/css/app.css` (Tailwind v4, `@theme`
  block + `:root` / `.dark` token sets). Fonts: Hanken Grotesk (sans),
  IBM Plex Mono (mono). After editing `app.css`, rebuild:
  `npm run build` (or in the app container).
- Panel config in `app/Providers/Filament/AdminPanelProvider.php`:
  terracotta `primary` ramp + warm `gray`, `maxContentWidth(SevenExtraLarge)`,
  `sidebarWidth('14rem')`, non-collapsible nav groups, brand logo, and the
  render hooks (breadcrumbs teleport target, usage banner, footer).
- Reusable CSS tokens you'll use: `--surface`, `--surface-2`, `--border`,
  `--muted`, `--faint`, `--accent`, `--radius-lg`, `--shadow-sm`,
  type scale `--t-xs/-sm/-h3/-h2`.

## 2. List pages

- Heading (H1) + one-line subtitle + a single primary "+ Neues X" button
  (terracotta). Filament renders these; keep the create action primary.
- Tables are themed globally (`.fi-ta*` in `app.css`): card radius, uppercase
  faint column headers, warm row hover. **Per-column** styling stays in the
  resource:
  - platform/provider → a chip with a globe icon (`<x-argos.chip>` or a
    Filament badge with `heroicon-o-globe-alt`),
  - repository / branch / stack / tag → `->fontFamily('mono')`,
  - counts (e.g. tasks) → a circular accent badge.
- Row click opens the record's detail (see §3) via `->recordUrl(...)`.

## 3. Detail = Edit

The detail view **is** the edit form wherever the record is editable.

- **Editable resources** (e.g. RepoProfile): no separate View page. Register
  only `index` / `create` / `edit`, with `edit` on the canonical
  `/{record}` route. `recordUrl` → `getUrl('edit', ...)`. Relation managers
  render on the Edit page (`getRelations()`), so nothing is lost by dropping
  View.
- **Read-only records** (built-in worker stacks, image builds — nothing to
  edit): keep a **View** page in the same styled layout (read-only). Branch
  `recordUrl` on the read-only flag, e.g.
  `$r->is_builtin ? getUrl('view', …) : getUrl('edit', …)`.
  Rationale: a disabled Filament form still dehydrates its fields, so a
  read-only *edit* page is unsafe — use a real View/infolist instead.

### Edit/View heading

Use the `App\Filament\Admin\Concerns\HasArgosEditHeading` trait on the
Edit/View page. It renders the heading as **record name + optional chip**
(matching the task-detail header):

```php
use HasArgosEditHeading;

protected function argosHeadingAttribute(): string { return 'label'; } // default 'name'

protected function argosHeadingChip(): ?array
{
    /** @var MyModel $record */
    $record = $this->getRecord();
    return ['icon' => 'heroicon-o-globe-alt', 'label' => $record->provider->value];
}
```

The trait resolves backed-enum attributes to their `->value`. The chip is
rendered with `<x-argos.chip>`.

## 4. Forms — aside sections

Every form section uses the two-column **aside** layout: icon + title +
description on the left (sticky), fields on the right.

```php
Section::make(__('…sections.platform'))
    ->description(__('…sections.platform_description')) // DE + EN, concise
    ->icon('heroicon-o-globe-alt')
    ->aside()
    ->schema([ /* fields */ ]),
```

- Pair fields side by side with `Grid::make(2)`; stack full-width groups with
  `Grid::make(1)`.
- The aside look (280px sticky header column, divider between sections,
  no per-section card) is delivered by the `.fi-section.fi-aside` rules in
  `app.css` — you don't style sections per resource.
- A resource with no sections yet (e.g. ApiClient) still wraps its fields in
  one aside `Section` so it matches.
- **Every** section needs a DE **and** EN `*_description` string in
  `lang/{de,en}/…`.

## 5. Logs / console

Any log/console output uses `<x-argos.terminal>` (dark, monospace, line
numbers), the same component as the task-detail log. Map raw lines to its
shape `['text' => …, 'class' => info|ok|warn|err|accent|cmd, 'n' => …]`.
Example: `resources/views/filament/admin/resources/image-build/build-log.blade.php`.

## 6. Components

`resources/views/components/argos/` holds the shared building blocks —
`chip`, `badge`, `phase-chip`, `btn`, `terminal`, `meta-strip`, `thread`,
`diff`, `respond`, etc. Reuse these before writing new markup; their styles
are the `.chip`, `.badge`, `.term*`, … classes in `app.css`.

## 7. Checklist — adding a new resource/page

1. List: heading + subtitle + primary create; mono/chip/badge columns;
   `recordUrl` to the detail.
2. Detail: editable → merge into Edit (no View); read-only → styled View.
   Add the `HasArgosEditHeading` trait + a chip.
3. Form: aside sections with icon + DE/EN description; `Grid` for field pairs.
4. Logs → `<x-argos.terminal>`.
5. Tests: render test via the embedding page; for View→Edit changes, repoint
   any `View…` page references. Run Pint → PHPStan → the filtered suite.
6. Strings: DE + EN for every new label/description.
