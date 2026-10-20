{{-- VS Code (OpenVSCode Server) of the workspace, served at code-<slug>.
     Loads through workspaces.open, which mints a fresh access ticket that the
     workspace gate trades for a host cookie. Embedded only where the browser
     will send that cookie in an iframe (same-site hosts); otherwise the
     editor opens in its own tab. --}}
@if (! (auth()->user()?->can('operate', $workspace) ?? false))
    <div class="ws-tab-empty muted">{{ __('workspaces.tabs.code_viewer') }}</div>
@elseif ($workspace->status === \App\Enums\WorkspaceStatus::Running)
    @php $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']); @endphp
    @if (\App\Support\WorkspaceHosts::embeddable())
        <div class="ws-frame-bar">
            <span class="muted">{{ __('workspaces.tabs.code_hint') }}</span>
            <a class="btn btn-secondary btn-sm" href="{{ $openUrl }}" target="_blank" rel="noopener">
                @svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open_new_tab') }}
            </a>
        </div>
        <iframe class="ws-frame" src="{{ $openUrl }}" title="{{ __('workspaces.tabs.code') }}" loading="lazy"
                allow="clipboard-read; clipboard-write"></iframe>
    @else
        <div class="ws-tab-empty">
            <p class="muted">{{ __('workspaces.tabs.not_embeddable') }}</p>
            <a class="btn btn-primary" href="{{ $openUrl }}" target="_blank" rel="noopener">
                @svg('heroicon-o-code-bracket') {{ __('workspaces.tabs.open_editor') }}
            </a>
        </div>
    @endif
@else
    <div class="ws-tab-empty muted">{{ __('workspaces.tabs.not_running') }}</div>
@endif
