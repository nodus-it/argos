@props([
    'events' => [],   // list of AgentStreamParser events
    'title' => 'worker',
    'live' => false,  // show a blinking cursor at the tail
    'chrome' => true, // wrap in the terminal frame; false = body only (page provides its own frame)
])

@php
    // Icon + CSS class per event kind. Keeps the markup below declarative.
    $icons = [
        'argos' => '│',
        'thinking' => '✳',
        'text' => '▍',
        'tool_use' => '◆',
        'tool_result' => '└',
        'result' => '✓',
    ];
@endphp

@if ($chrome)
    <div class="term">
        <div class="term-head">
            <span class="term-dots"><i style="background:#f5655b"></i><i style="background:#f5bf4f"></i><i style="background:#57c75e"></i></span>
            <span class="term-title">{{ $title }}</span>
        </div>
        <div class="as">
            @include('components.argos.partials.agent-stream-body', ['events' => $events, 'icons' => $icons, 'live' => $live])
        </div>
    </div>
@else
    @include('components.argos.partials.agent-stream-body', ['events' => $events, 'icons' => $icons, 'live' => $live])
@endif
