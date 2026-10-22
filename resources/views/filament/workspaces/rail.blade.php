{{-- Icon rail of the workspaces panel (replaces the top bar): logo, "My
     work", the workspaces as abbreviations with waiting badges, the list of
     all workspaces, administration (Argos admins), the bell and the user
     menu. Icon-only, 44px targets; a bottom bar on phones. --}}
@php
    use App\Filament\Workspaces\Pages\MyWork;
    use App\Filament\Workspaces\Resources\WorkspaceResource;

    $user = filament()->auth()->user();
    $onMyWork = request()->routeIs(MyWork::getRouteName(filament()->getPanel('workspaces')));
    $onList = request()->routeIs('filament.workspaces.resources.workspaces.index');
    $record = request()->route('record');
    // The route names the workspace by slug (older links: ULID).
    $activeId = $record instanceof \App\Models\Workspace ? $record->id : (is_string($record) ? \App\Models\Workspace::query()->where('slug', $record)->orWhere('id', $record)->value('id') : null);
@endphp
@if ($user)
<nav class="wf-rail" aria-label="{{ __('workspaces.panel.navigation') }}">
    <a href="{{ MyWork::getUrl(panel: 'workspaces') }}" class="wf-rail-logo" aria-label="Argos">
        <x-wf.icon name="eye" :size="24" />
    </a>
    <a href="{{ MyWork::getUrl(panel: 'workspaces') }}" @class(['wf-rail-btn', 'wf-rail-home', 'is-active' => $onMyWork])
       aria-label="{{ __('workspaces.my_work.title') }}" title="{{ __('workspaces.my_work.title') }}" @if ($onMyWork) aria-current="page" @endif>
        <x-wf.icon name="inbox" :size="20" />
    </a>
    <span class="wf-rail-sep" aria-hidden="true"></span>
    @livewire(\App\Livewire\Workspace\RailWorkspaces::class, ['activeId' => $activeId], key('rail-workspaces'))
    <a href="{{ WorkspaceResource::getUrl('index', panel: 'workspaces') }}" @class(['wf-rail-btn', 'wf-rail-all', 'is-active' => $onList])
       aria-label="{{ __('workspaces.rail.all_workspaces') }}" title="{{ __('workspaces.rail.all_workspaces') }}" @if ($onList) aria-current="page" @endif>
        <x-wf.icon name="grid" :size="20" />
    </a>
    <span class="wf-rail-spacer"></span>
    @if ($user->isAdmin())
        <a href="{{ url('/admin') }}" class="wf-rail-btn wf-rail-admin" aria-label="{{ __('workspaces.panel.admin') }}" title="{{ __('workspaces.panel.admin') }}">
            <x-wf.icon name="settings" :size="20" />
        </a>
    @endif
    @livewire(\App\Livewire\Workspace\RailNotifications::class, ['lazy' => true])
    <x-filament::dropdown placement="right-end" class="wf-rail-user">
        <x-slot name="trigger">
            <button type="button" @class(['wf-rail-avatar', 'has-image' => $user->avatarUrl() !== null]) aria-label="{{ __('workspaces.panel.signed_in_as', ['name' => $user->name]) }}" title="{{ $user->name }}">@if ($user->avatarUrl() !== null)<img src="{{ $user->avatarUrl() }}" alt="">@else{{ \App\Support\Initials::of($user->name) }}@endif</button>
        </x-slot>
        <x-filament::dropdown.header icon="heroicon-o-user">{{ $user->name }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @if (filament()->hasProfile())
                <x-filament::dropdown.list.item :href="filament()->getProfileUrl()" tag="a" icon="heroicon-o-user-circle">
                    {{ __('workspaces.panel.profile') }}
                </x-filament::dropdown.list.item>
            @endif
            @if ($user->isAdmin())
                <x-filament::dropdown.list.item :href="url('/admin')" tag="a" icon="heroicon-o-cog-6-tooth">
                    {{ __('workspaces.panel.admin') }}
                </x-filament::dropdown.list.item>
            @endif
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
