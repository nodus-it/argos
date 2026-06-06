{{-- Dashboard hero widget. Polls every 3s (set via $pollingInterval on widget).
     The hidden poll trigger re-renders this widget with fresh running/waiting counts
     and the latest phase_run ticker lines. --}}
<x-filament-widgets::widget>
    <div wire:poll.3s class="hidden"></div>

    @php
        $title = __('dashboard.hero.title', [
            'hl_open' => '<span class="hl">',
            'hl_close' => '</span>',
        ]);
        $sub = $running > 0
            ? __('dashboard.hero.live', ['count' => $running])
            : __('dashboard.hero.idle');
    @endphp

    <x-argos.dashboard-hero
        :title="$title"
        :sub="$sub"
        :ticker-lines="$tickerLines"
        :idle-text="__('dashboard.hero.idle')"
        :running="$running"
        :waiting="$waiting"
        :failed="$failed"
    />
</x-filament-widgets::widget>
