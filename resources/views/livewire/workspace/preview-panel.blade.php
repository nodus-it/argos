@php
    use App\Enums\DemoStatus;
    /** @var \App\Models\Demo|null $demo */
    $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'preview', 'task' => $task->id]);
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
<div class="ag-env ag-env-tile" x-data="{ log: @js($demo?->status === DemoStatus::Failed) }" :class="{ 'is-wide': log }" @if ($building) wire:poll.3s @endif>
    <div class="ag-env-row">
        <span class="ag-env-icon"><x-ag.icon name="globe" :size="16" /></span>
        <span class="ag-env-name">{{ __('workspaces.tabs.preview') }}</span>
        @if ($reason === null && $live)
            <a class="ag-btn ag-btn-sm ag-btn-soft ag-env-open" href="{{ $openUrl }}" target="argos-preview-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
        @elseif ($reason === null && $canOperate && ! $building)
            <button type="button" class="ag-btn ag-btn-sm ag-btn-soft ag-env-open" wire:click="start">{{ __('workspaces.preview.start_short') }}</button>
        @endif
    </div>
    <div class="ag-env-row">
        <span class="ag-env-state {{ $stateClass }}" @if ($reason !== null) title="{{ $reason }}" @elseif ($live && $demo->ttl_until) title="{{ __('workspaces.preview.expires', ['time' => $demo->ttl_until->diffForHumans()]) }}" @endif>
            @if ($building)<span class="ag-spin"></span>@endif{{ $state }}
        </span>
        @if ($reason === null)
            <span class="ag-env-actions">
                @if ($canOperate && $live)
                    <button type="button" class="ag-icon-btn ag-icon-btn-sm" wire:click="rerun" title="{{ __('workspaces.preview.rerun') }}" aria-label="{{ __('workspaces.preview.rerun') }}"><x-ag.icon name="play" :size="13" :stroke="2" /></button>
                    <button type="button" class="ag-icon-btn ag-icon-btn-sm" wire:click="start" title="{{ __('workspaces.preview.rebuild') }}" aria-label="{{ __('workspaces.preview.rebuild') }}"><x-ag.icon name="refresh" :size="13" :stroke="2" /></button>
                    <button type="button" class="ag-icon-btn ag-icon-btn-sm" wire:click="stop" title="{{ __('workspaces.preview.stop') }}" aria-label="{{ __('workspaces.preview.stop') }}"><x-ag.icon name="stop" :size="13" :stroke="2" /></button>
                @endif
                @if ($demo?->build_log)
                    <button type="button" class="ag-icon-btn ag-icon-btn-sm" x-on:click="log = ! log" :class="{ 'is-on': log }" title="{{ __('workspaces.preview.log') }}" aria-label="{{ __('workspaces.preview.log') }}"><x-ag.icon name="file" :size="13" :stroke="2" /></button>
                @endif
            </span>
        @endif
    </div>
    @if ($reason !== null)
        <p class="ag-locked" data-testid="preview-reason">{{ $reason }}</p>
    @elseif ($login !== null)
        <p class="ag-demo-login" data-testid="preview-login">{{ __('workspaces.preview.login') }}: <span class="mono" x-on:click="window.getSelection().selectAllChildren($el)">{{ $login['email'] }}</span> · <span class="mono" x-on:click="window.getSelection().selectAllChildren($el)">{{ $login['password'] }}</span></p>
    @endif
    @if ($reason === null && $demo?->build_log)
        <pre class="ag-log mono" x-show="log" x-cloak>{{ \Illuminate\Support\Str::limit($demo->build_log, 20000) }}</pre>
    @endif
</div>
