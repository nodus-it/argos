<?php

declare(strict_types=1);

it('renders a running status badge with a pulsing dot', function (): void {
    $this->blade('<x-argos.badge status="running" label="Läuft" />')
        ->assertSee('badge badge-running', false)
        ->assertSee('class="dot"', false)
        ->assertSee('Läuft');
});

it('renders a success badge with an icon and label', function (): void {
    $this->blade('<x-argos.badge status="success" label="Done" />')
        ->assertSee('badge-success', false)
        ->assertSee('<svg', false)
        ->assertSee('Done');
});

it('falls back to the draft badge for an unknown status', function (): void {
    $this->blade('<x-argos.badge status="bogus" />')
        ->assertSee('badge-draft', false);
});

it('renders an active phase chip with its label', function (): void {
    $this->blade('<x-argos.phase-chip phase="concept" :active="true" label="Concept" />')
        ->assertSee('chip on', false)
        ->assertSee('<svg', false)
        ->assertSee('Concept');
});

it('renders a primary button as a link when href is given', function (): void {
    $this->blade('<x-argos.btn variant="primary" href="/go" icon="heroicon-o-bolt">Start</x-argos.btn>')
        ->assertSee('<a', false)
        ->assertSee('btn btn-primary', false)
        ->assertSee('href="/go"', false)
        ->assertSee('Start');
});

it('renders a small ghost button as a button element', function (): void {
    $this->blade('<x-argos.btn variant="ghost" size="sm">Cancel</x-argos.btn>')
        ->assertSee('btn btn-ghost btn-sm', false)
        ->assertSee('type="button"', false);
});

it('renders the phase rail with node states and a current note', function (): void {
    $rail = [
        ['phase' => 'draft', 'state' => 'done', 'label' => 'Draft'],
        ['phase' => 'concept', 'state' => 'active', 'label' => 'Concept'],
        ['phase' => 'implement', 'state' => 'todo', 'label' => 'Implement'],
    ];

    $this->blade('<x-argos.phase-rail :rail="$rail" current="Concept" sub="running now" />', ['rail' => $rail])
        ->assertSee('class="rail"', false)
        ->assertSee('st-done', false)
        ->assertSee('rail-node st-active pulse', false)
        ->assertSee('Concept')
        ->assertSee('running now');
});

it('renders a meta strip with items', function (): void {
    $this->blade(<<<'BLADE'
        <x-argos.meta-strip>
            <x-argos.meta-item label="Repository">acme/widget</x-argos.meta-item>
            <x-argos.meta-item label="Branch" :mono="true" :link="true">feat/x</x-argos.meta-item>
        </x-argos.meta-strip>
    BLADE)
        ->assertSee('meta-strip', false)
        ->assertSee('ms-item', false)
        ->assertSee('Repository')
        ->assertSee('ms-v mono link', false);
});

it('renders the terminal with classed lines', function (): void {
    $lines = [
        ['text' => 'cloning…', 'class' => 'info', 'time' => '12:00:01'],
        ['text' => 'done', 'class' => 'ok'],
    ];

    $this->blade('<x-argos.terminal title="worker · feat/x" :lines="$lines" />', ['lines' => $lines])
        ->assertSee('class="term"', false)
        ->assertSee('worker · feat/x')
        ->assertSee('t-ok', false)
        ->assertSee('done');
});

it('renders a thread item with title, actions and detail', function (): void {
    $this->blade(<<<'BLADE'
        <x-argos.thread-item phase="concept" :done="true" title="Concept created" who="Claude Code" cost="$0.12" time="2m">
            The proposed approach.
            <x-slot:actions><button class="link-btn">View concept</button></x-slot:actions>
            <x-slot:detail><div class="term">log</div></x-slot:detail>
        </x-argos.thread-item>
    BLADE)
        ->assertSee('feed-item', false)
        ->assertSee('feed-node st-done', false)
        ->assertSee('Concept created')
        ->assertSee('feed-actions', false)
        ->assertSee('feed-detail', false);
});

it('renders the respond composer in the waiting state', function (): void {
    $this->blade('<x-argos.respond :waiting="true" flag="Agent wartet" />')
        ->assertSee('respond respond-dock is-waiting', false)
        ->assertSee('respond-flag', false)
        ->assertSee('Agent wartet');
});

it('renders the kebab action menu with a danger item', function (): void {
    $this->blade(<<<'BLADE'
        <x-argos.action-menu>
            <x-argos.menu-item icon="heroicon-o-trash" :danger="true">Delete</x-argos.menu-item>
        </x-argos.action-menu>
    BLADE)
        ->assertSee('menu-wrap', false)
        ->assertSee('x-data', false)
        ->assertSee('menu-item danger', false)
        ->assertSee('Delete');
});
