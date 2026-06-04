{{-- Warm-Paper dashboard stat cards. Renders the widget's Stat objects as
     .stat control-room cards (ARGOS_REDESIGN.md §5.11). Live cards (value > 0,
     i.e. non-gray colour) get the accent bar + accent number.

     The custom view must carry wire:poll itself — the default stats-overview
     view renders it from $pollingInterval, and dropping it (as the warm-paper
     rewrite did) silently disabled the dashboard auto-refresh. --}}
<x-filament-widgets::widget>
    {{-- Interpolating the interval into the attribute *name* (wire:poll.{{ … }})
         breaks Blade's component-tag tokeniser, so the modifier is literal —
         it mirrors the widget's $pollingInterval = '5s'. --}}
    @if ($this->getPollingInterval())
        <div wire:poll.5s class="hidden"></div>
    @endif
    <div class="stats">
        @foreach ($this->getCachedStats() as $stat)
            @php
                $color = $stat->getColor();
                $live = $color !== null && $color !== 'gray';
                $tone = match ($color) {
                    'success' => 'ok',
                    'warning', 'danger' => 'wn',
                    default => null,
                };
                $descriptionIcon = $stat->getDescriptionIcon();
            @endphp
            <div @class(['stat', 'is-live' => $live])>
                <span class="accent-bar"></span>
                <div class="lbl">{{ $stat->getLabel() }}</div>
                <div class="num">{{ $stat->getValue() }}</div>
                <div class="meta">
                    @if ($descriptionIcon)
                        @svg($descriptionIcon)
                    @endif
                    <span @class([$tone])>{{ $stat->getDescription() }}</span>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
