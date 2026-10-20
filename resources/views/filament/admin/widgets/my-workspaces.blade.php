<x-filament-widgets::widget>
    <div class="ws-overview" wire:poll.30s
         x-data="{ timer: null }"
         x-on:argos-workspace-signal.window="clearTimeout(timer); timer = setTimeout(() => $wire.$refresh(), 400)">
        <div class="ws-overview-head">
            <span class="fi-ta-argos-heading">@svg('heroicon-o-command-line', 'fi-ta-argos-heading-icon') <span>{{ __('workspaces.overview.heading') }}</span></span>
            <a class="btn btn-ghost btn-sm" href="{{ \App\Filament\Workspaces\Resources\WorkspaceResource::getUrl('index', panel: 'workspaces') }}">{{ __('workspaces.overview.all') }}</a>
        </div>
        <div class="ws-overview-grid">
            @foreach ($rows as $row)
                @php($ws = $row['workspace'])
                @php($sum = $row['summary'])
                <a @class(['ws-overview-card', 'card', 'is-waiting' => $sum['waiting_for_me']]) href="{{ $ws->url() }}" wire:key="ws-{{ $ws->id }}">
                    <div class="ws-overview-title">
                        <span>{{ $ws->name }}</span>
                        <span class="badge {{ \App\Support\BadgeClass::for($ws->status->color()) }}">{{ $ws->status->label() }}</span>
                    </div>
                    <div class="ws-overview-meta muted mono">{{ $ws->repoProfile->name }} · {{ $ws->branch }}</div>
                    <div class="ws-overview-state">
                        @if ($sum['waiting_for_me'])
                            <span class="badge badge-running">@svg('heroicon-o-hand-raised') {{ __('workspaces.overview.waiting_for_you') }}</span>
                        @endif
                        @if ($sum['working'] > 0)
                            <span class="badge badge-running"><span class="ws-dot"></span> {{ trans_choice('workspaces.overview.working', $sum['working'], ['count' => $sum['working']]) }}</span>
                        @endif
                        <span class="muted">{{ trans_choice('workspaces.overview.threads', $sum['threads'], ['count' => $sum['threads']]) }}</span>
                        @if ($sum['pr_state'])
                            <span class="badge badge-draft">PR · {{ __('workspaces.pr_state.'.$sum['pr_state']) }}</span>
                        @endif
                        @if ($sum['preview'] === 'live')
                            <span class="badge badge-success">{{ __('workspaces.tabs.preview') }}</span>
                        @endif
                    </div>
                    <div class="ws-overview-time muted">{{ $ws->last_activity_at?->diffForHumans() }}</div>
                </a>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
