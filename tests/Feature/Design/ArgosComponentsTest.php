<?php

declare(strict_types=1);

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
