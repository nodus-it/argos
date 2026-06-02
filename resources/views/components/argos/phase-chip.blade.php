@props([
    'phase' => 'draft',
    'active' => false,
    'label' => null,
])
{{-- Phase chip — icon + phase name. See ARGOS_REDESIGN.md §5.2. --}}
@php
    $map = [
        'draft' => ['icon' => 'heroicon-o-document-text', 'label' => 'Draft'],
        'concept' => ['icon' => 'heroicon-o-light-bulb', 'label' => 'Concept'],
        'implement' => ['icon' => 'heroicon-o-code-bracket', 'label' => 'Implement'],
        'push' => ['icon' => 'heroicon-o-arrow-up-tray', 'label' => 'Push'],
        'review' => ['icon' => 'heroicon-o-chat-bubble-left-right', 'label' => 'Review'],
        'respond' => ['icon' => 'heroicon-o-chat-bubble-left-right', 'label' => 'Respond'],
    ];
    $m = $map[$phase] ?? $map['draft'];
@endphp
<span {{ $attributes->merge(['class' => 'chip'.($active ? ' on' : '')]) }}>
    @svg($m['icon'])
    {{ $label ?? $m['label'] }}
</span>
