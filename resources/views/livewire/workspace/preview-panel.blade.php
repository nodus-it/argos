@php
    use App\Enums\DemoStatus;
    /** @var \App\Models\Demo|null $demo */
    $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'preview']);
    $live = $demo?->status === DemoStatus::Live;
    [$state, $stateClass] = match (true) {
        $reason !== null => [__('workspaces.preview.off_short'), ''],
        $building => [$queued ? __('workspaces.preview.queued') : __('workspaces.preview.building'), 'is-warn'],
        $live => [__('workspaces.preview.live'), 'is-ok'],
        $demo?->status === DemoStatus::Failed => [__('workspaces.preview.failed_short'), 'is-error'],
        $demo?->status === DemoStatus::Stopped => [__('workspaces.preview.stopped_short'), ''],
        default => [__('workspaces.preview.none_short'), ''],
    };
@endphp

{{-- Preview row of the context sidebar; the preview opens in its own (named,
     reused) tab — apps often refuse to be framed. --}}
<div class="wf-env" x-data="{ log: @js($demo?->status === DemoStatus::Failed) }" @if ($building) wire:poll.3s @endif>
    <div class="wf-env-row">
        <span class="wf-env-icon"><x-wf.icon name="globe" :size="18" /></span>
        <span class="wf-env-text">
            <span class="wf-env-name">{{ __('workspaces.tabs.preview') }}</span>
            <span class="wf-env-state {{ $stateClass }}" @if ($reason !== null) title="{{ $reason }}" @elseif ($live && $demo->ttl_until) title="{{ __('workspaces.preview.expires', ['time' => $demo->ttl_until->diffForHumans()]) }}" @endif>
                @if ($building)<span class="wf-spin"></span>@endif{{ $state }}
            </span>
        </span>
        @if ($reason === null)
            @if ($canOperate && $live)
                <button type="button" class="wf-icon-btn wf-icon-btn-sm" wire:click="rerun" title="{{ __('workspaces.preview.rerun') }}" aria-label="{{ __('workspaces.preview.rerun') }}"><x-wf.icon name="refresh" :size="15" :stroke="2" /></button>
                <button type="button" class="wf-icon-btn wf-icon-btn-sm" wire:click="stop" title="{{ __('workspaces.preview.stop') }}" aria-label="{{ __('workspaces.preview.stop') }}"><x-wf.icon name="stop" :size="15" :stroke="2" /></button>
            @endif
            @if ($demo?->build_log)
                <button type="button" class="wf-icon-btn wf-icon-btn-sm" x-on:click="log = ! log" :class="{ 'is-on': log }" title="{{ __('workspaces.preview.log') }}" aria-label="{{ __('workspaces.preview.log') }}"><x-wf.icon name="file" :size="15" :stroke="2" /></button>
            @endif
            @if ($live)
                <a class="wf-btn wf-btn-sm wf-btn-soft" href="{{ $openUrl }}" target="argos-preview-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
            @elseif ($canOperate && ! $building)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="start">{{ __('workspaces.preview.start_short') }}</button>
            @endif
        @endif
    </div>
    @if ($reason === null && $demo?->build_log)
        <pre class="wf-log mono" x-show="log" x-cloak>{{ \Illuminate\Support\Str::limit($demo->build_log, 20000) }}</pre>
    @endif
    @if ($reason === null && $canOperate && $live)
        <button type="button" class="wf-link wf-env-sub" wire:click="start">{{ __('workspaces.preview.rebuild') }}</button>
    @endif
</div>
