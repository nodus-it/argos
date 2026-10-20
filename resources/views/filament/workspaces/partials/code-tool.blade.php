{{-- VS Code (OpenVSCode Server) at code-<slug>, opened in its own named tab
     (one per workspace, reused): the full editor with its shortcuts. --}}
<div class="ws-tool">
    <div class="ws-tool-head">
        <span class="ws-tool-icon">@svg('heroicon-o-code-bracket')</span>
        <span class="ws-tool-name">VS Code</span>
        @if (! $canOperate)
            <span class="ws-chip">{{ __('workspaces.tabs.viewer_short') }}</span>
        @else
            <span @class(['ws-chip', 'is-ok' => $running])>{{ $running ? __('workspaces.tabs.ready') : __('workspaces.tabs.not_running_short') }}</span>
        @endif
    </div>
    <div class="ws-tool-actions">
        @if ($canOperate && $running)
            <a class="ws-tool-main" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']) }}" target="argos-code-{{ $workspace->slug }}">
                @svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open') }}
            </a>
        @endif
    </div>
</div>
