{{-- Chronological task thread container. Fill with <x-argos.thread-item>.
     See docs/design/argos/ARGOS_REDESIGN.md §5.7. --}}
<div {{ $attributes->merge(['class' => 'feed']) }}>
    {{ $slot }}
</div>
