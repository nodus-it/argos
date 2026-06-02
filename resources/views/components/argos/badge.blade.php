@props([
    'status' => 'draft',
    'label' => null,
])
{{--
    Status badge — color + icon + label, never colour alone (WCAG AA).
    See docs/design/argos/ARGOS_REDESIGN.md §5.1.
    `running` shows a pulsing dot instead of an icon.
--}}
@php
    $map = [
        'draft' => ['cls' => 'badge-draft', 'icon' => 'heroicon-o-document-text', 'label' => 'Draft'],
        'running' => ['cls' => 'badge-running', 'icon' => null, 'label' => 'Running'],
        'waiting' => ['cls' => 'badge-waiting', 'icon' => 'heroicon-o-hand-raised', 'label' => 'Waiting'],
        'success' => ['cls' => 'badge-success', 'icon' => 'heroicon-o-check-circle', 'label' => 'Done'],
        'failed' => ['cls' => 'badge-failed', 'icon' => 'heroicon-o-x-circle', 'label' => 'Failed'],
    ];
    $m = $map[$status] ?? $map['draft'];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.$m['cls']]) }}>
    @if ($status === 'running')
        <span class="dot"></span>
    @elseif ($m['icon'])
        @svg($m['icon'])
    @endif
    {{ $label ?? $m['label'] }}
</span>
