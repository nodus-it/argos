{{-- VS Code (OpenVSCode Server) of the workspace at code-<slug>, opened in
     its own named tab (one per workspace, reused): the full editor with its
     shortcuts, and no same-site constraints for embedding. --}}
<div class="ws-tool ws-tool-code">
    <span class="ws-tool-label">@svg('heroicon-o-code-bracket') {{ __('workspaces.tabs.code') }}</span>
    @if (! (auth()->user()?->can('operate', $workspace) ?? false))
        <span class="muted ws-tool-note">{{ __('workspaces.tabs.code_viewer') }}</span>
    @elseif ($workspace->status === \App\Enums\WorkspaceStatus::Running)
        <a class="btn btn-primary btn-sm" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']) }}" target="argos-code-{{ $workspace->slug }}">
            @svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open_editor') }}
        </a>
    @else
        <span class="muted ws-tool-note">{{ __('workspaces.tabs.not_running') }}</span>
    @endif
</div>
