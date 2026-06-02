@props([
    'icon' => null,
    'href' => null,
    'danger' => false,
])
{{-- A single action-menu row. Renders an <a> when :href is given, else a
     <button>. `:danger` tints it red. See ARGOS_REDESIGN.md §5.4. --}}
@php
    $cls = 'menu-item'.($danger ? ' danger' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon) @svg($icon) @endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button {{ $attributes->merge(['class' => $cls, 'type' => 'button']) }}>
        @if ($icon) @svg($icon) @endif
        <span>{{ $slot }}</span>
    </button>
@endif
