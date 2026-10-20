@php
    use App\Enums\DemoStatus;
    /** @var \App\Models\Demo|null $demo */
    $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'preview']);
    $live = $demo?->status === DemoStatus::Live;
@endphp

{{-- Preview controls in the workbench bar; the preview itself opens in its
     own (named, reused) browser tab — apps often refuse to be framed. --}}
<div class="ws-tool ws-tool-preview" @if ($building) wire:poll.3s @endif>
    <span class="ws-tool-label">@svg('heroicon-o-globe-alt') {{ __('workspaces.tabs.preview') }}</span>

    @if ($reason !== null)
        <span class="muted ws-tool-note">{{ $reason }}</span>
    @else
        @if ($building)
            <span class="badge badge-running"><span class="ws-dot"></span> {{ $queued ? __('workspaces.preview.queued') : __('workspaces.preview.building') }}</span>
        @elseif ($live)
            <span class="badge badge-success" @if ($demo->ttl_until) title="{{ __('workspaces.preview.expires', ['time' => $demo->ttl_until->diffForHumans()]) }}" @endif>{{ __('workspaces.preview.live') }}</span>
        @elseif ($demo?->status === DemoStatus::Failed)
            <span class="badge badge-failed">{{ __('workspaces.preview.failed_short') }}</span>
        @else
            <span class="badge badge-draft">{{ $demo?->status === DemoStatus::Stopped ? __('workspaces.preview.stopped_short') : __('workspaces.preview.none_short') }}</span>
        @endif

        @if ($live)
            <a class="btn btn-primary btn-sm" href="{{ $openUrl }}" target="argos-preview-{{ $workspace->slug }}">@svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open_preview') }}</a>
        @endif
        @if ($canOperate)
            @if ($live)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="rerun" title="{{ __('workspaces.preview.rerun') }}">@svg('heroicon-o-arrow-path') <span class="ws-tool-text">{{ __('workspaces.preview.rerun') }}</span></button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="start" title="{{ __('workspaces.preview.rebuild') }}">@svg('heroicon-o-wrench-screwdriver') <span class="ws-tool-text">{{ __('workspaces.preview.rebuild') }}</span></button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="stop" title="{{ __('workspaces.preview.stop') }}">@svg('heroicon-o-stop')</button>
            @elseif (! $building)
                <button type="button" class="btn btn-secondary btn-sm" wire:click="start">@svg('heroicon-o-play') {{ __('workspaces.preview.start') }}</button>
            @endif
        @endif

        @if ($demo?->build_log)
            <details class="ws-tool-log" @if ($demo->status === DemoStatus::Failed) open @endif>
                <summary>{{ __('workspaces.preview.log') }}</summary>
                <pre class="mono">{{ \Illuminate\Support\Str::limit($demo->build_log, 20000) }}</pre>
            </details>
        @endif
    @endif
</div>
