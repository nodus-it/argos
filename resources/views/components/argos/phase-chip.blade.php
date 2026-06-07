@props([
    'phase' => 'draft',
    'active' => false,
    'label' => null,
])
{{-- Phase chip — icon + phase name. See ARGOS_REDESIGN.md §5.2. --}}
<span {{ $attributes->merge(['class' => 'chip'.($active ? ' on' : '')]) }}>
    @svg(\App\Support\PhaseGlyph::icon($phase))
    {{ $label ?? \App\Support\PhaseGlyph::label($phase) }}
</span>
