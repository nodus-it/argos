@props([
    'rail' => [],      // list of ['phase' => ..., 'state' => done|active|wait|fail|todo, 'label' => ...]
    'current' => null, // bold current-phase label on the right
    'sub' => null,     // subtext under it
])
{{-- Horizontal phase progress over the 5 phases. Display only, not clickable.
     See docs/design/argos/ARGOS_REDESIGN.md §5.5. --}}
@php
    $phaseIcons = [
        'draft' => 'heroicon-o-document-text',
        'concept' => 'heroicon-o-light-bulb',
        'implement' => 'heroicon-o-code-bracket',
        'push' => 'heroicon-o-arrow-up-tray',
        'review' => 'heroicon-o-chat-bubble-left-right',
    ];
@endphp
<div class="rail">
    @foreach ($rail as $i => $node)
        @php
            $state = $node['state'] ?? 'todo';
            $phase = $node['phase'] ?? 'draft';
        @endphp
        @if ($i > 0)
            <div @class(['rail-line', 'done' => ($rail[$i - 1]['state'] ?? '') === 'done'])></div>
        @endif
        <div @class(['rail-node', 'st-'.$state, 'pulse' => $state === 'active'])>
            <div class="rail-dot">
                @switch($state)
                    @case('done') @svg('heroicon-o-check') @break
                    @case('wait') @svg('heroicon-o-hand-raised') @break
                    @case('fail') @svg('heroicon-o-x-mark') @break
                    @default @svg($phaseIcons[$phase] ?? $phaseIcons['draft'])
                @endswitch
            </div>
            <div class="rail-lbl">{{ $node['label'] ?? __('tasks.rail.'.$phase) }}</div>
        </div>
    @endforeach

    @if ($current)
        <div class="rail-note">
            <div class="rail-cur">{{ $current }}</div>
            @if ($sub)
                <div class="rail-sub">{{ $sub }}</div>
            @endif
        </div>
    @endif
</div>
