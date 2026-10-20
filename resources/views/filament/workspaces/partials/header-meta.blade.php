@php
    /** @var \App\Models\Workspace $workspace */
    $statusBadge = \App\Support\BadgeClass::for($workspace->status->color());
@endphp
{{-- One compact line under the workspace name (the page header). --}}
<span class="ws-headline">
    <span class="badge {{ $statusBadge }}">{{ $workspace->status->label() }}</span>
    <span class="ws-headline-item" title="{{ __('workspaces.fields.project') }}">@svg('heroicon-o-folder') {{ $workspace->repoProfile->name }}</span>
    <span class="ws-headline-item mono" title="{{ __('workspaces.fields.branch') }}">@svg('heroicon-o-arrow-turn-down-right') {{ $workspace->branch }} <span class="muted">← {{ $workspace->base_branch }}</span></span>
    @if ($workspace->pr_url)
        <a class="ws-headline-item" href="{{ $workspace->pr_url }}" target="_blank" rel="noopener" title="{{ __('workspaces.ship.pr') }}">
            @svg('heroicon-o-arrow-top-right-on-square') #{{ $workspace->pr_number }}
            @if ($workspace->pr_state)
                <span class="badge {{ $workspace->pr_state === 'merged' ? 'badge-success' : ($workspace->pr_state === 'open' ? 'badge-running' : 'badge-draft') }}">{{ __('workspaces.pr_state.'.$workspace->pr_state) }}</span>
            @endif
        </a>
    @endif
    @if ($workspace->external_issue)
        <a class="ws-headline-item" href="{{ $workspace->external_issue['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.fields.issue') }}">
            @svg('heroicon-o-ticket') {{ app(\App\Services\Workspace\WorkspaceIssueService::class)->label($workspace->external_issue['number'], $workspace->external_issue['title']) }}
        </a>
    @endif
    <span class="ws-headline-members" title="{{ __('workspaces.fields.members') }}">
        @foreach ($workspace->members()->with('user')->get() as $member)
            <span class="ws-avatar ws-avatar-sm" title="{{ $member->user->name }} ({{ $member->role->label() }})">{{ \Illuminate\Support\Str::of($member->user->name)->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}</span>
        @endforeach
    </span>
</span>
