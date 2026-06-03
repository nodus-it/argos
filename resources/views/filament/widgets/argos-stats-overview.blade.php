{{-- Warm-Paper dashboard stat cards. Renders the widget's Stat objects as
     .stat control-room cards (ARGOS_REDESIGN.md §5.11). Live cards (value > 0,
     i.e. non-gray colour) get the accent bar + accent number. --}}
<x-filament-widgets::widget>
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
