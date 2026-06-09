@props([
    'label' => 'Actions',
])
{{--
    Kebab action menu — one primary button per screen lives outside; everything
    else goes in here. See ARGOS_REDESIGN.md §5.4. Alpine-driven dropdown.
    Slot holds <x-argos.menu-item> entries (and <div class="menu-div"> separators).
--}}
<div class="menu-wrap" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button
        type="button"
        class="iconbtn"
        :aria-expanded="open"
        aria-haspopup="true"
        aria-label="{{ $label }}"
        @click="open = !open"
    >
        @svg('heroicon-o-ellipsis-vertical')
    </button>

    <div
        class="menu"
        x-show="open"
        x-cloak
        x-transition.origin.top.right
        @click.outside="open = false"
        @click="open = false"
        style="display:none"
    >
        {{ $slot }}
    </div>
</div>
