@php
    use App\Enums\DemoStatus;
    /** @var \App\Models\Demo|null $demo */
    $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'preview']);
@endphp

<div class="ws-preview" @if ($building) wire:poll.3s @endif>
    @if ($reason !== null)
        <div class="ws-tab-empty muted">{{ $reason }}</div>
    @else
        <div class="ws-frame-bar">
            @if ($demo?->status === DemoStatus::Live && $demo->ttl_until)
                <span class="muted">{{ __('workspaces.preview.expires', ['time' => $demo->ttl_until->diffForHumans()]) }}</span>
            @endif
            <span class="ws-spacer"></span>
            @if ($canOperate)
                @if ($demo?->status === DemoStatus::Live)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="rerun">@svg('heroicon-o-arrow-path') {{ __('workspaces.preview.rerun') }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="start">@svg('heroicon-o-wrench-screwdriver') {{ __('workspaces.preview.rebuild') }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="stop">@svg('heroicon-o-stop') {{ __('workspaces.preview.stop') }}</button>
                @elseif (! $building)
                    <button type="button" class="btn btn-primary btn-sm" wire:click="start">@svg('heroicon-o-play') {{ __('workspaces.preview.start') }}</button>
                @endif
            @endif
            @if ($demo?->status === DemoStatus::Live)
                <a class="btn btn-secondary btn-sm" href="{{ $openUrl }}" target="_blank" rel="noopener">@svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open_new_tab') }}</a>
            @endif
        </div>

        @if ($building)
            <div class="ws-tab-empty"><span class="ws-dot"></span> {{ $queued ? __('workspaces.preview.queued') : __('workspaces.preview.building') }}</div>
        @elseif ($demo?->status === DemoStatus::Live)
            @if (\App\Support\WorkspaceHosts::embeddable())
                <iframe class="ws-frame" src="{{ $openUrl }}" title="{{ __('workspaces.tabs.preview') }}" loading="lazy"></iframe>
            @else
                <div class="ws-tab-empty">
                    <p class="muted">{{ __('workspaces.tabs.not_embeddable') }}</p>
                    <a class="btn btn-primary" href="{{ $openUrl }}" target="_blank" rel="noopener">@svg('heroicon-o-globe-alt') {{ __('workspaces.tabs.open_preview') }}</a>
                </div>
            @endif
        @elseif ($demo?->status === DemoStatus::Failed)
            <div class="ws-alert ws-alert-error">@svg('heroicon-o-exclamation-triangle') <span>{{ __('workspaces.preview.failed') }}</span></div>
        @elseif ($demo?->status === DemoStatus::Stopped)
            <div class="ws-tab-empty muted">{{ __('workspaces.preview.stopped') }}</div>
        @else
            <div class="ws-tab-empty muted">{{ __('workspaces.preview.none') }}</div>
        @endif

        @if ($demo?->build_log)
            <details class="ws-log">
                <summary>{{ __('workspaces.preview.log') }}</summary>
                <pre class="mono">{{ \Illuminate\Support\Str::limit($demo->build_log, 20000) }}</pre>
            </details>
        @endif
    @endif
</div>
