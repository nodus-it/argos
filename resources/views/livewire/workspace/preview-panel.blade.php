@php
    use App\Enums\DemoStatus;
    /** @var \App\Models\Demo|null $demo */
    $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'preview']);
    $live = $demo?->status === DemoStatus::Live;
    [$chip, $chipClass] = match (true) {
        $reason !== null => [__('workspaces.preview.off_short'), ''],
        $building => [$queued ? __('workspaces.preview.queued') : __('workspaces.preview.building'), 'is-busy'],
        $live => [__('workspaces.preview.live'), 'is-ok'],
        $demo?->status === DemoStatus::Failed => [__('workspaces.preview.failed_short'), 'is-error'],
        $demo?->status === DemoStatus::Stopped => [__('workspaces.preview.stopped_short'), ''],
        default => [__('workspaces.preview.none_short'), ''],
    };
@endphp

{{-- Preview controls; the preview opens in its own (named, reused) tab —
     apps often refuse to be framed. Same layout as the VS Code tool. --}}
<div class="ws-tool" x-data="{ log: @js($demo?->status === DemoStatus::Failed) }" @if ($building) wire:poll.3s @endif>
    <div class="ws-tool-head">
        <span class="ws-tool-icon">@svg('heroicon-o-globe-alt')</span>
        <span class="ws-tool-name">{{ __('workspaces.tabs.preview') }}</span>
        <span class="ws-chip {{ $chipClass }}" @if ($reason !== null) title="{{ $reason }}" @elseif ($live && $demo->ttl_until) title="{{ __('workspaces.preview.expires', ['time' => $demo->ttl_until->diffForHumans()]) }}" @endif>
            @if ($building)<span class="ws-dot"></span>@endif {{ $chip }}
        </span>
    </div>

    @if ($reason === null)
        <div class="ws-tool-actions">
            @if ($canOperate && $live)
                <button type="button" class="ws-tool-icon-btn" wire:click="rerun" title="{{ __('workspaces.preview.rerun') }}" aria-label="{{ __('workspaces.preview.rerun') }}">@svg('heroicon-o-arrow-path')</button>
                <button type="button" class="ws-tool-icon-btn" wire:click="start" title="{{ __('workspaces.preview.rebuild') }}" aria-label="{{ __('workspaces.preview.rebuild') }}">@svg('heroicon-o-wrench-screwdriver')</button>
                <button type="button" class="ws-tool-icon-btn" wire:click="stop" title="{{ __('workspaces.preview.stop') }}" aria-label="{{ __('workspaces.preview.stop') }}">@svg('heroicon-o-stop-circle')</button>
            @endif
            @if ($demo?->build_log)
                <button type="button" class="ws-tool-icon-btn" x-on:click="log = ! log" :class="{ 'is-on': log }" title="{{ __('workspaces.preview.log') }}" aria-label="{{ __('workspaces.preview.log') }}">@svg('heroicon-o-document-text')</button>
            @endif
            @if ($live)
                <a class="ws-tool-main" href="{{ $openUrl }}" target="argos-preview-{{ $workspace->slug }}">@svg('heroicon-o-arrow-top-right-on-square') {{ __('workspaces.tabs.open') }}</a>
            @elseif ($canOperate && ! $building)
                <button type="button" class="ws-tool-main" wire:click="start">@svg('heroicon-o-play') {{ __('workspaces.preview.start_short') }}</button>
            @endif
        </div>

        @if ($demo?->build_log)
            <pre class="ws-tool-log mono" x-show="log" x-cloak>{{ \Illuminate\Support\Str::limit($demo->build_log, 20000) }}</pre>
        @endif
    @endif
</div>
