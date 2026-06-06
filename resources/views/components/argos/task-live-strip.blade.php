@props([
    'phase' => null,  // Phase enum or null
    'lines' => [],    // array of ['text' => '', 'class' => '']
])
{{--
    Live working strip — shown only when a phase is actively running.
    Displays a pulsing indicator, phase label, and up to 2 log lines.
    Rendered inside .th-hero by the task detail hero.
--}}
<div class="th-live">
    <span class="th-live-tag">
        <span class="dot" aria-hidden="true"></span>
        {{ $phase ? __('tasks.view.live.' . $phase->value . '_running', [], app()->getLocale()) : __('tasks.view.live.running') }}
    </span>

    <div class="th-stream" aria-live="polite">
        @forelse ($lines as $line)
            <div @class(['th-stream-line', $line['class'] => isset($line['class'])])>{{ $line['text'] }}</div>
        @empty
            <div class="th-stream-line">{{ __('tasks.view.live.waiting') }}</div>
        @endforelse
    </div>
</div>
