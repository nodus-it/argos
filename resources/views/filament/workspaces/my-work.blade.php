@php
    use App\Filament\Workspaces\Pages\MyWork;
    use App\Support\Initials;

    /** @var array<string, list<array{task: \App\Models\Task, state: \App\Enums\TaskState}>> $groups */
    $total = array_sum(array_map('count', $groups));
@endphp
{{-- "My work": own and invited tasks across all workspaces, grouped by what
     they need. Rows are cards; the title link covers the whole card, the
     ticket and PR chips stay separately clickable. --}}
<div class="wf-mywork" wire:poll.30s
     x-data="{ timer: null }"
     x-on:argos-workspace-signal.window="clearTimeout(timer); timer = setTimeout(() => $wire.$refresh(), 400)">
    <header class="wf-mw-head">
        <div class="wf-mw-heading">
            <h1 class="wf-mw-title">{{ __('workspaces.my_work.title') }}</h1>
            <p class="wf-mw-sub">{{ __('workspaces.my_work.subtitle') }}</p>
        </div>
        <a class="wf-btn" href="{{ $workspacesUrl }}">
            <x-wf.icon name="grid" :size="16" :stroke="2" /> {{ __('workspaces.my_work.all_workspaces') }}
        </a>
    </header>

    @if ($total === 0)
        <div class="wf-mw-empty">
            <span class="wf-mw-empty-icon" aria-hidden="true"><x-wf.icon name="check" :size="22" :stroke="2" /></span>
            <h2 class="wf-mw-empty-title">{{ __('workspaces.my_work.empty_title') }}</h2>
            <p class="wf-mw-empty-text">{{ __('workspaces.my_work.empty_hint') }}</p>
            <div class="wf-mw-empty-actions">
                @if ($createUrl)
                    <a class="wf-btn wf-btn-primary" href="{{ $createUrl }}"><x-wf.icon name="plus" :size="16" :stroke="2" /> {{ __('workspaces.actions.create') }}</a>
                @endif
                <a class="wf-btn" href="{{ $workspacesUrl }}">{{ __('workspaces.my_work.open_workspaces') }}</a>
            </div>
        </div>
    @else
        @foreach ($groups as $group => $rows)
            @continue($rows === [])
            <section class="wf-mw-group is-{{ $group }}" aria-labelledby="wf-mw-{{ $group }}" data-group="{{ $group }}">
                <h2 class="wf-mw-group-title" id="wf-mw-{{ $group }}">
                    {{ __('workspaces.my_work.groups.'.$group) }} <span class="wf-mw-count">{{ count($rows) }}</span>
                </h2>
                <ul class="wf-mw-list" role="list">
                    @foreach ($rows as $row)
                        @php
                            $task = $row['task'];
                            $state = $row['state'];
                            $ref = $task->external_ref;
                        @endphp
                        <li class="wf-mw-row" wire:key="mw-{{ $task->id }}" data-task="{{ $task->id }}">
                            <span class="wf-dot is-{{ $group === 'pr_open' ? 'info' : ($group === 'ready' ? 'done' : $group) }}" aria-hidden="true"></span>
                            <div class="wf-mw-main">
                                <a class="wf-mw-link" href="{{ $task->url() }}">
                                    @if ($task->auto_state)<x-wf.icon name="zap" :size="13" :stroke="2" class="wf-accent" label="{{ __('workspaces.auto.label') }}" />@endif
                                    {{ $task->title }}
                                </a>
                                <div class="wf-mw-meta">
                                    <span class="wf-mw-ws">{{ $task->workspace->name }}</span>
                                    <span class="wf-mw-state is-{{ $state->color() }}">{{ $state->label() }}</span>
                                    @if ($ref !== null)
                                        <a class="wf-mw-chip is-ticket" href="{{ $ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $ref['title'] ?? '' }}">{{ MyWork::ticketLabel($ref) }}</a>
                                    @endif
                                    @if ($task->pr_number !== null)
                                        <a class="wf-mw-chip is-pr is-{{ $task->pr_state ?? 'open' }}" href="{{ $task->pr_url ?? '#' }}" target="_blank" rel="noopener">
                                            <x-wf.icon name="git-pr" :size="12" :stroke="2" /> #{{ $task->pr_number }} · {{ __('workspaces.pr_state.'.($task->pr_state ?? 'open')) }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                            <div class="wf-mw-side">
                                @if ($task->owner_user_id !== $user->id && $task->owner)
                                    <span class="wf-avatar-xs" title="{{ __('workspaces.my_work.owner', ['name' => $task->owner->name]) }}" role="img" aria-label="{{ __('workspaces.my_work.owner', ['name' => $task->owner->name]) }}">{{ Initials::of($task->owner->name) }}</span>
                                @endif
                                <time class="wf-mw-time" datetime="{{ $task->updated_at?->toIso8601String() }}">{{ $task->updated_at?->diffForHumans(short: true) }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($group === 'ready' && $readyMore > 0)
                    <p class="wf-mw-more">{{ trans_choice('workspaces.my_work.ready_more', $readyMore, ['count' => $readyMore]) }}</p>
                @endif
            </section>
        @endforeach
    @endif
</div>
