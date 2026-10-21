{{-- Icon rail of the workspaces panel (replaces the top bar): home,
     administration, the bell and the user menu. Icon-only, 44px targets. --}}
@php
    use App\Filament\Workspaces\Resources\WorkspaceResource;

    $user = filament()->auth()->user();
@endphp
@if ($user)
<nav class="wf-rail" aria-label="{{ __('workspaces.panel.navigation') }}">
    <a href="{{ WorkspaceResource::getUrl('index', panel: 'workspaces') }}" class="wf-rail-logo" aria-label="Argos">
        <x-wf.icon name="eye" :size="24" />
    </a>
    <a href="{{ WorkspaceResource::getUrl('index', panel: 'workspaces') }}" @class(['wf-rail-btn', 'is-active' => request()->routeIs('filament.workspaces.*')])
       aria-label="{{ __('workspaces.panel.workspaces') }}" title="{{ __('workspaces.panel.workspaces') }}" @if (request()->routeIs('filament.workspaces.*')) aria-current="page" @endif>
        <x-wf.icon name="layout" :size="20" />
    </a>
    <a href="{{ url('/admin') }}" class="wf-rail-btn" aria-label="{{ __('workspaces.panel.admin') }}" title="{{ __('workspaces.panel.admin') }}">
        <x-wf.icon name="settings" :size="20" />
    </a>
    <span class="wf-rail-spacer"></span>
    @livewire(\App\Livewire\Workspace\RailNotifications::class, ['lazy' => true])
    <x-filament::dropdown placement="right-end" class="wf-rail-user">
        <x-slot name="trigger">
            <button type="button" class="wf-rail-avatar" aria-label="{{ __('workspaces.panel.signed_in_as', ['name' => $user->name]) }}" title="{{ $user->name }}">{{ \App\Support\Initials::of($user->name) }}</button>
        </x-slot>
        <x-filament::dropdown.header icon="heroicon-o-user">{{ $user->name }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @if (filament()->hasProfile())
                <x-filament::dropdown.list.item :href="filament()->getProfileUrl()" tag="a" icon="heroicon-o-user-circle">
                    {{ __('workspaces.panel.profile') }}
                </x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item :href="url('/admin')" tag="a" icon="heroicon-o-cog-6-tooth">
                {{ __('workspaces.panel.admin') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <x-filament-panels::theme-switcher />
        </x-filament::dropdown.list>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item :action="filament()->getLogoutUrl()" method="post" tag="form" icon="heroicon-o-arrow-left-on-rectangle">
                {{ __('filament-panels::layout.actions.logout.label') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</nav>
@endif
