@props([
    'waiting' => false,         // amber-highlight when the agent waits for feedback
    'flag' => 'The agent is waiting for your feedback.',
])
{{--
    Sticky respond composer docked at the bottom. Presentational shell — the
    caller supplies the textarea + send button (default slot) and optional
    quick-action chips (`quick` slot), wiring them to Livewire. §5.8.
--}}
<div @class(['respond', 'respond-dock', 'is-waiting' => $waiting])>
    <div class="respond-inner">
        @if ($waiting)
            <div class="respond-flag">
                @svg('heroicon-o-hand-raised')
                {{ $flag }}
            </div>
        @endif

        <div class="respond-body">
            <x-argos.avatar>Du</x-argos.avatar>
            {{ $slot }}
        </div>

        @isset($quick)
            <div class="respond-quick">{{ $quick }}</div>
        @endisset
    </div>
</div>
