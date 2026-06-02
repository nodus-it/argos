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
