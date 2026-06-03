@props([
    'variant' => 'secondary',
    'size' => null,
    'icon' => null,
    'href' => null,
])
{{-- Button — variants primary/secondary/ghost/success/danger; optional `sm`
     size and leading Heroicon. Renders an <a> when :href is given. §5.3. --}}
@php
    $cls = 'btn btn-'.$variant.($size === 'sm' ? ' btn-sm' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon) @svg($icon) @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['class' => $cls, 'type' => 'button']) }}>
        @if ($icon) @svg($icon) @endif
        {{ $slot }}
    </button>
@endif
