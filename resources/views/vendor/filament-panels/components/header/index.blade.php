{{--
    Override of Filament's page header (filament-panels::components.header).
    Identical to the packaged view EXCEPT the breadcrumbs are teleported into
    the topbar slot (#argos-topbar-breadcrumbs, injected via the TOPBAR_START
    render hook) instead of rendering in the content header — matching the
    Warm-Paper design. Re-check against the vendor view on Filament upgrades.
--}}
@props([
    'actions' => [],
    'actionsAlignment' => null,
    'breadcrumbs' => [],
    'heading' => null,
    'subheading' => null,
])

<header
    {{
        $attributes->class([
            'fi-header',
            'fi-header-has-breadcrumbs' => $breadcrumbs,
        ])
    }}
>
    <div>
        @if ($breadcrumbs)
            <template x-teleport="#argos-topbar-breadcrumbs">
                <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
            </template>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, scopes: $this->getRenderHookScopes()) }}

        @if (filled($heading))
            <h1 class="fi-header-heading">
                {{ $heading }}
            </h1>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, scopes: $this->getRenderHookScopes()) }}

        @if (filled($subheading))
            <p class="fi-header-subheading">
                {{ $subheading }}
            </p>
        @endif
    </div>

    @php
        $beforeActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $this->getRenderHookScopes());
        $afterActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER, scopes: $this->getRenderHookScopes());
    @endphp

    @if (filled($beforeActions) || $actions || filled($afterActions))
        <div class="fi-header-actions-ctn">
            {{ $beforeActions }}

            @if ($actions)
                <x-filament::actions
                    :actions="$actions"
                    :alignment="$actionsAlignment"
                />
            @endif

            {{ $afterActions }}
        </div>
    @endif
</header>
