@php
    use App\Filament\App\Pages\MyWork;
    use App\Support\Initials;

    /** @var array<string, list<array{task: \App\Models\Task, state: \App\Enums\TaskState}>> $groups */
    $total = array_sum(array_map('count', $groups));
@endphp
{{-- "My work": own and invited tasks across all workspaces, grouped by what
     they need. Rows are cards; the title link covers the whole card, the
     ticket and PR chips stay separately clickable. --}}
<div class="ag-mywork" wire:poll.30s
     x-data="{ timer: null }"
     x-on:argos-workspace-signal.window="clearTimeout(timer); timer = setTimeout(() => $wire.$refresh(), 400)">
    <header class="ag-mw-head">
        <div class="ag-mw-heading">
            <h1 class="ag-mw-title">{{ __('workspaces.my_work.title') }}</h1>
            <p class="ag-mw-sub">{{ __('workspaces.my_work.subtitle') }}</p>
        </div>
        <a class="ag-btn" href="{{ $workspacesUrl }}">
            <x-ag.icon name="grid" :size="16" :stroke="2" /> {{ __('workspaces.my_work.all_workspaces') }}
        </a>
    </header>

    @if ($total === 0 && (! $firstSteps['agent'] || ! $firstSteps['workspace']))
        {{-- A new person: what is missing before the first task. --}}
        <section class="ag-mw-steps" aria-labelledby="ag-mw-steps-title" data-testid="first-steps">
            <h2 class="ag-mw-empty-title" id="ag-mw-steps-title">{{ __('workspaces.my_work.steps.title') }}</h2>
            <ol class="ag-mw-steps-list">
                <li @class(['is-done' => $firstSteps['agent']])>
                    <span class="ag-mw-step-mark" aria-hidden="true">@if ($firstSteps['agent'])<x-ag.icon name="check" :size="14" :stroke="2.5" />@else 1 @endif</span>
                    <div>
                        <strong>{{ __('workspaces.my_work.steps.agent') }}</strong>
                        <p>{{ __('workspaces.my_work.steps.agent_hint') }}</p>
                        @unless ($firstSteps['agent'])<a class="ag-btn ag-btn-primary" href="{{ $agentsUrl }}">{{ __('workspaces.my_work.steps.agent_action') }}</a>@endunless
                    </div>
                </li>
                <li @class(['is-done' => $firstSteps['workspace']])>
                    <span class="ag-mw-step-mark" aria-hidden="true">@if ($firstSteps['workspace'])<x-ag.icon name="check" :size="14" :stroke="2.5" />@else 2 @endif</span>
                    <div>
                        <strong>{{ __('workspaces.my_work.steps.workspace') }}</strong>
                        <p>{{ $firstSteps['admins'] !== [] ? __('workspaces.my_work.steps.workspace_hint_named', ['names' => implode(', ', $firstSteps['admins'])]) : __('workspaces.my_work.steps.workspace_hint') }}</p>
                    </div>
                </li>
                <li>
                    <span class="ag-mw-step-mark" aria-hidden="true">3</span>
                    <div>
                        <strong>{{ __('workspaces.my_work.steps.task') }}</strong>
                        <p>{{ __('workspaces.my_work.steps.task_hint') }}</p>
                        <a class="ag-btn" href="{{ $docsUrl }}">{{ __('workspaces.my_work.steps.docs') }}</a>
                    </div>
                </li>
            </ol>
        </section>
    @elseif ($total === 0)
        <div class="ag-mw-empty">
            <span class="ag-mw-empty-icon" aria-hidden="true"><x-ag.icon name="check" :size="22" :stroke="2" /></span>
            <h2 class="ag-mw-empty-title">{{ __('workspaces.my_work.empty_title') }}</h2>
            <p class="ag-mw-empty-text">{{ __('workspaces.my_work.empty_hint') }}</p>
            <div class="ag-mw-empty-actions">
                @if ($createUrl)
                    <a class="ag-btn ag-btn-primary" href="{{ $createUrl }}"><x-ag.icon name="plus" :size="16" :stroke="2" /> {{ __('workspaces.actions.create') }}</a>
                @endif
                <a class="ag-btn" href="{{ $workspacesUrl }}">{{ __('workspaces.my_work.open_workspaces') }}</a>
            </div>
        </div>
    @else
        @foreach ($groups as $group => $rows)
            @continue($rows === [])
            <section class="ag-mw-group is-{{ $group }}" aria-labelledby="ag-mw-{{ $group }}" data-group="{{ $group }}">
                <h2 class="ag-mw-group-title" id="ag-mw-{{ $group }}">
                    {{ __('workspaces.my_work.groups.'.$group) }} <span class="ag-mw-count">{{ count($rows) }}</span>
                </h2>
                <ul class="ag-mw-list" role="list">
                    @foreach ($rows as $row)
                        @php
                            $task = $row['task'];
                            $state = $row['state'];
                            $ref = $task->external_ref;
                        @endphp
                        <li class="ag-mw-row" wire:key="mw-{{ $task->id }}" data-task="{{ $task->id }}">
                            <span class="ag-dot is-{{ $group === 'pr_open' ? 'info' : ($group === 'ready' ? 'done' : $group) }}" aria-hidden="true"></span>
                            <div class="ag-mw-main">
                                <a class="ag-mw-link" href="{{ $task->url() }}">
                                    @if ($task->auto_state)<x-ag.icon name="zap" :size="13" :stroke="2" class="ag-accent" label="{{ __('workspaces.auto.label') }}" />@endif
                                    {{ $task->title }}
                                </a>
                                <div class="ag-mw-meta">
                                    <span class="ag-mw-ws">{{ $task->workspace->name }}</span>
                                    <span class="ag-mw-state is-{{ $state->color() }}">{{ $state->label() }}</span>
                                    @if ($ref !== null)
                                        <a class="ag-mw-chip is-ticket" href="{{ $ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $ref['title'] ?? '' }}">{{ \App\Support\TaskRefs::label($ref) }}</a>
                                    @endif
                                    @if ($task->pr_number !== null)
                                        <a class="ag-mw-chip is-pr is-{{ $task->pr_state ?? 'open' }}" href="{{ $task->pr_url ?? '#' }}" target="_blank" rel="noopener">
                                            <x-ag.icon name="git-pr" :size="12" :stroke="2" /> #{{ $task->pr_number }} · {{ __('workspaces.pr_state.'.($task->pr_state ?? 'open')) }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                            <div class="ag-mw-side">
                                @if ($task->owner_user_id !== $user->id && $task->owner)
                                    <x-ag.avatar :user="$task->owner" title="{{ __('workspaces.my_work.owner', ['name' => $task->owner->name]) }}" role="img" aria-label="{{ __('workspaces.my_work.owner', ['name' => $task->owner->name]) }}" />
                                @endif
                                <time class="ag-mw-time" datetime="{{ $task->updated_at?->toIso8601String() }}">{{ $task->updated_at?->diffForHumans(short: true) }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($group === 'ready' && $readyMore > 0)
                    <p class="ag-mw-more">{{ trans_choice('workspaces.my_work.ready_more', $readyMore, ['count' => $readyMore]) }}</p>
                @endif
            </section>
        @endforeach
    @endif
</div>
