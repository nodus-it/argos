@props([
    'icon' => null,
    'active' => false,
])
{{-- Generic chip (icon + label). Used for platform/status chips next to
     headings and inline. See docs/design/argos/ARGOS_REDESIGN.md §5.2. --}}
<span {{ $attributes->merge(['class' => 'chip'.($active ? ' on' : '')]) }}>
    @if ($icon) @svg($icon) @endif
    {{ $slot }}
</span>
