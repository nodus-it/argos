@props([
    'extra' => null, // optional slot: second row, revealed by the Details toggle
])
{{-- Meta strip under the task header: first row always visible, optional second
     row behind a Details chevron. Fill with <x-argos.meta-item>. §5.6. --}}
<div class="meta-strip" x-data="{ open: false }">
    <div class="ms-row">
        {{ $slot }}
        @if ($extra)
            <button type="button" class="ms-more" @click="open = !open" :aria-expanded="open">
                <span x-text="open ? '{{ __('Less') }}' : '{{ __('Details') }}'">{{ __('Details') }}</span>
                @svg('heroicon-o-chevron-down')
            </button>
        @endif
    </div>
    @if ($extra)
        <div class="ms-extra" x-show="open" x-cloak style="display:none">
            <div class="ms-row">{{ $extra }}</div>
        </div>
    @endif
</div>
