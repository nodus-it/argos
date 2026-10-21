@php
    use App\Enums\ApprovalKind;
    use App\Enums\ApprovalStatus;
    use App\Enums\AutoTaskState;
    use App\Enums\PermissionMode;
    use App\Enums\TaskEventType;
    use App\Livewire\Workspace\TaskChat;
    use App\Support\TaskEventFormatter as Fmt;

    /** @var \App\Models\Workspace $workspace */
    /** @var \App\Models\Task|null $task */
    $streamConfig = [
        'workspaceId' => $workspace->id,
        'taskId' => $task?->id,
        'userId' => $user->id,
        'userName' => $user->name,
        'status' => $task?->session_status->value,
        'labels' => [
            'typing' => __('workspaces.task.typing', ['name' => '__NAME__']),
        ],
    ];
@endphp

<div class="ws-chat wf-chat" x-data="window.argosTaskStream ? window.argosTaskStream(@js($streamConfig)) : {}" x-init="$nextTick(() => scrollDown?.())"
     x-on:scroll-to-approval.window="setTimeout(() => $root.querySelector('.ws-approval.is-pending')?.scrollIntoView({ block: 'center', behavior: 'smooth' }), 700)">
    <div class="wf-chat-main">
        {{-- Auto-task state and who else is here (the task itself is picked in the header). --}}
        @if ($task?->auto_state)
            @php
                $autoRounds = $task->auto_state === AutoTaskState::Fixing || $task->auto_state === AutoTaskState::Gating
                    ? ' · '.__('workspaces.auto.round', ['n' => (int) ($task->auto_counters['fix_rounds'] ?? 0), 'max' => (int) ($task->auto_config['max_fix_rounds'] ?? 0)])
                    : '';
            @endphp
            <div class="wf-task-bar">
                <span class="ws-chip ws-auto-chip is-{{ $task->auto_state->color() }}" title="{{ $task->auto_message }}">
                    @svg('heroicon-s-bolt') {{ $task->auto_state->label() }}{{ $autoRounds }}
                </span>
                @if ($canWrite)
                    @if ($task->auto_state->isRunning())
                        <button type="button" class="wf-icon-btn wf-icon-btn-sm" wire:click="pauseAuto" title="{{ __('workspaces.auto.pause') }}" aria-label="{{ __('workspaces.auto.pause') }}"><x-wf.icon name="pause" :size="15" :stroke="2" /></button>
                    @elseif ($task->auto_state !== AutoTaskState::Done)
                        <button type="button" class="wf-icon-btn wf-icon-btn-sm" wire:click="resumeAuto" title="{{ __('workspaces.auto.resume') }}" aria-label="{{ __('workspaces.auto.resume') }}"><x-wf.icon name="play" :size="15" :stroke="2" /></button>
                    @endif
                @endif
            </div>
        @endif
        <div class="wf-presence" x-show="members && members.length > 1" x-cloak>
            <span class="wf-muted">{{ __('workspaces.task.present') }}</span>
            <template x-for="m in members" :key="m.id">
                <span class="ws-avatar ws-avatar-sm" :title="m.name" x-text="initials(m.name)"></span>
            </template>
        </div>

    @if ($task?->auto_state && $task->auto_message && ! $task->auto_state->isRunning())
        <div @class(['ws-alert', 'ws-auto-note', 'ws-alert-error' => in_array($task->auto_state, [AutoTaskState::Failed, AutoTaskState::BudgetExceeded], true), 'ws-alert-warn' => $task->auto_state === AutoTaskState::Paused]) role="status">
            @svg('heroicon-o-bolt') <span>{{ $task->auto_message }}</span>
        </div>
    @endif

    @if ($task !== null && $task->kind === \App\Enums\TaskKind::Setup && ! $task->status->isFinished())
        <div class="ws-alert ws-setup-note" role="status" data-testid="setup-banner">
            @svg('heroicon-o-wrench-screwdriver')
            <span>{{ __('workspaces.setup.banner') }}@if ($task->workspace->setup_status) · <strong>{{ $task->workspace->setup_status->label() }}</strong>@endif</span>
            @if ($canWrite)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-primary" wire:click="testSetup" wire:loading.attr="disabled" title="{{ __('workspaces.setup.test_help') }}"
                        @disabled($task->workspace->setup_status === \App\Enums\WorkspaceSetupStatus::Testing)>{{ __('workspaces.setup.test') }}</button>
            @endif
        </div>
    @endif

    {{-- New task form --}}
    @if ($creating && $canWrite)
        <div class="card card-pad ws-new-task">
            @if ($agents->isEmpty())
                <p class="muted">{{ __('workspaces.task.no_agent') }}</p>
                <a class="btn btn-secondary btn-sm" href="{{ \App\Filament\Admin\Resources\AgentCredentialResource::getUrl('create', panel: 'admin') }}">{{ __('workspaces.task.add_agent') }}</a>
            @else
                <form wire:submit="createTask" class="ws-form">
                    <label class="ws-inline ws-auto-toggle">
                        <input type="checkbox" wire:model.live="newAuto">
                        <span><strong>{{ __('workspaces.auto.toggle') }}</strong> — {{ __('workspaces.auto.toggle_help') }}</span>
                    </label>
                    @if ($newAuto)
                        <label class="ws-field">
                            <span>{{ __('workspaces.auto.prompt') }}</span>
                            <textarea class="respond-ta ws-ta" rows="4" wire:model="newPrompt" maxlength="20000" placeholder="{{ __('workspaces.auto.prompt_placeholder') }}" required></textarea>
                            @error('newPrompt') <small class="t-err">{{ $message }}</small> @enderror
                        </label>
                        <div class="ws-auto-grid">
                            <label class="ws-field">
                                <span>{{ __('projects.fields.auto_plan') }}</span>
                                <select class="ws-select" wire:model="newPlan">
                                    <option value="">{{ __('workspaces.auto.project_default', ['value' => \App\Enums\AutoPlanMode::from($autoDefaults['plan'])->label()]) }}</option>
                                    @foreach (\App\Enums\AutoPlanMode::cases() as $m)
                                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="ws-field">
                                <span>{{ __('projects.fields.auto_ship') }}</span>
                                <select class="ws-select" wire:model="newShip">
                                    <option value="">{{ __('workspaces.auto.project_default', ['value' => \App\Enums\AutoShipMode::from($autoDefaults['ship'])->label()]) }}</option>
                                    @foreach (\App\Enums\AutoShipMode::cases() as $m)
                                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="ws-field">
                                <span>{{ __('projects.fields.auto_max_fix_rounds') }}</span>
                                <input type="number" class="ws-input" wire:model="newMaxFixRounds" min="0" max="20" placeholder="{{ $autoDefaults['max_fix_rounds'] }}">
                            </label>
                        </div>
                    @endif
                    @if ($newParentId !== null)
                        <p class="wf-new-follow"><x-wf.icon name="arrow-right" :size="13" :stroke="2" /> {{ __('workspaces.task.follow_up_hint') }}</p>
                    @endif
                    @if ($issuesEnabled)
                        <div class="ws-field">
                            <span>{{ __('workspaces.task.ticket') }}</span>
                            @if ($chosenIssue !== null)
                                <span class="wf-ref-chip wf-new-ticket">
                                    <x-wf.icon name="link" :size="11" :stroke="2" /> {{ $chosenIssue }}
                                    <button type="button" class="wf-text-btn" wire:click="chooseIssue(null)" aria-label="{{ __('workspaces.task.ticket_clear') }}"><x-wf.icon name="x" :size="12" :stroke="2" /></button>
                                </span>
                            @else
                                <input type="search" class="ws-input" wire:model.live.debounce.300ms="newIssueQuery" placeholder="{{ __('workspaces.task.ticket_search') }}" autocomplete="off">
                                @if ($issueResults !== [])
                                    <ul class="wf-new-issues" role="listbox">
                                        @foreach ($issueResults as $number => $label)
                                            <li><button type="button" role="option" wire:click="chooseIssue(@js((string) $number))">{{ $label }}</button></li>
                                        @endforeach
                                    </ul>
                                @elseif ($newIssueQuery !== '')
                                    <small class="muted">{{ __('workspaces.task.ticket_none') }}</small>
                                @endif
                            @endif
                            @error('newIssue') <small class="t-err">{{ $message }}</small> @enderror
                            <small class="muted">{{ __('workspaces.task.ticket_help') }}</small>
                        </div>
                    @endif
                    <label class="ws-field">
                        <span>{{ __('workspaces.task.title') }}</span>
                        <input type="text" class="ws-input" wire:model="newTitle" maxlength="120" placeholder="{{ __('workspaces.task.title_default') }}">
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.task.base_branch') }}</span>
                        <input type="text" class="ws-input mono" wire:model="newBaseBranch" maxlength="255" placeholder="{{ $workspace->default_branch }}">
                        @error('newBaseBranch') <small class="t-err">{{ $message }}</small> @enderror
                        <small class="muted">{{ __('workspaces.task.base_branch_help') }}</small>
                    </label>
                    <label class="ws-inline">
                        <input type="checkbox" wire:model="newAutoMerge" data-testid="new-auto-merge">
                        <span>{{ __('workspaces.pr.auto_merge') }} — <span class="muted">{{ __('workspaces.pr.auto_merge_help') }}</span></span>
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.task.agent') }}</span>
                        <select class="ws-select" wire:model.live="newAgentId">
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }}{{ $agent->user ? ' · '.$agent->user->name : ' · '.__('worker.credentials.fields.owner_shared') }}</option>
                            @endforeach
                        </select>
                        <small class="muted">{{ __('workspaces.task.agent_help') }}</small>
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.task.model') }}</span>
                        <select class="ws-select" wire:model="newModel">
                            <option value="">{{ __('workspaces.task.model_default') }}</option>
                            @foreach ($newModels as $model)
                                <option value="{{ $model['value'] }}" title="{{ $model['description'] }}">{{ $model['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ws-field" @if ($newAuto) hidden @endif>
                        <span>{{ __('workspaces.task.mode') }}</span>
                        <select class="ws-select" wire:model="newMode">
                            @foreach ($modes as $mode)
                                @if ($mode !== PermissionMode::BypassPermissions || $isOwner)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    @error('newAgentId') <p class="t-err">{{ $message }}</p> @enderror
                    <p class="muted ws-hint">{{ __('workspaces.task.own_copy_hint') }}</p>
                    <div class="ws-form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">{{ $newAuto ? __('workspaces.auto.start') : __('workspaces.task.create') }}</button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if ($task === null)
        @unless ($creating)
            <div class="ws-empty muted">{{ __('workspaces.task.none') }}</div>
        @endunless
    @else
        {{-- History: a person's messages; per Claude turn a head (duration,
             cost), compact step rows and the text/approvals in between. --}}
        <div class="ws-feed wf-feed" x-ref="feed">
            @if ($hasOlder)
                <button type="button" class="wf-btn wf-btn-sm ws-older" wire:click="loadOlder">{{ __('workspaces.task.load_older') }}</button>
            @endif

            @foreach ($timeline as $block)
                @if ($block['kind'] === 'user')
                    @php $event = $block['event']; $p = $event->payload; $mine = $event->author_user_id === $user->id; @endphp
                    <div @class(['wf-msg', 'ws-msg', 'is-other' => ! $mine && empty($p['auto']), 'is-author-'.((int) $event->author_user_id % 4) => ! $mine]) wire:key="ev-{{ $event->id }}">
                        <div class="wf-msg-meta">
                            @if ($mine)
                                <span>{{ __('workspaces.events.you') }} · {{ $event->created_at?->diffForHumans() }}</span>
                            @else
                                <span class="wf-msg-author"><span class="wf-avatar-xs" aria-hidden="true">{{ \App\Support\Initials::of((string) ($p['author_name'] ?? '?')) }}</span>{{ $p['author_name'] ?? '' }}</span>
                                <span>· {{ $event->created_at?->diffForHumans() }}</span>
                            @endif
                            @if (! empty($p['auto']))
                                <span class="wf-pill-tag"><x-wf.icon name="zap" :size="11" :stroke="2" /> {{ __('workspaces.auto.automated') }}</span>
                            @endif
                            @if (! empty($p['pending']))
                                <span class="wf-pill-tag is-warn">{{ __('workspaces.events.queued', ['position' => $queuePosition ?? 1]) }}</span>
                            @elseif (! isset($p['accepted_at']) && $busy)
                                <span class="wf-pill-tag">{{ __('workspaces.task.queued') }}</span>
                            @endif
                            @if ($canWrite && isset($checkpoints[$event->id]))
                                <button type="button" class="ws-reset-btn" wire:click="previewRestore('{{ $checkpoints[$event->id] }}')" title="{{ __('workspaces.checkpoints.reset_here_hint') }}">
                                    <x-wf.icon name="undo" :size="13" :stroke="2" /> <span>{{ __('workspaces.checkpoints.reset_here') }}</span>
                                </button>
                            @endif
                        </div>
                        <div class="wf-msg-text">{{ $p['text'] ?? '' }}</div>
                        @if (! empty($p['images']))
                            <div class="ws-images">
                                @foreach ($p['images'] as $image)
                                    @if (! empty($image['path']))
                                        @php $imageUrl = route('tasks.attachments', ['task' => $task, 'file' => basename($image['path'])]); @endphp
                                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener"><img src="{{ $imageUrl }}" alt="{{ __('workspaces.task.image') }}" loading="lazy"></a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @php $firstId = $block['items'][0]['event']->id ?? ($block['items'][0]['rows'][0]['id'] ?? $loop->index); @endphp
                    <div class="wf-turn" wire:key="turn-{{ $firstId }}">
                        <div class="wf-msg-meta">
                            <span class="wf-claude-badge" aria-hidden="true">C</span>
                            <span>{{ __('workspaces.events.claude') }}
                                @if ($block['running'])
                                    · <span x-data="{ s: {{ \App\Support\TaskTimeline::elapsed($block['started_at']) }} }" x-init="setInterval(() => s++, 1000)" x-text="@js(__('workspaces.chat.working_for', ['seconds' => '__S__'])).replace('__S__', s)">{{ __('workspaces.chat.working_for', ['seconds' => \App\Support\TaskTimeline::elapsed($block['started_at'])]) }}</span>
                                @elseif ($block['duration_ms'] !== null)
                                    · {{ $block['cost'] ? __('workspaces.chat.meta', ['duration' => number_format($block['duration_ms'] / 1000, 0, ',', '.'), 'cost' => \App\Support\CostFormatter::usd((float) $block['cost'])]) : __('workspaces.chat.meta_duration', ['duration' => number_format($block['duration_ms'] / 1000, 0, ',', '.')]) }}
                                @endif
                            </span>
                        </div>
                        @php
                            $turnSteps = collect($block['items'])->where('kind', 'steps')->flatMap(fn ($i) => $i['rows']);
                            $turnFiles = $turnSteps->filter(fn ($r) => $r['add'] !== null)->pluck('target')->unique()->count();
                        @endphp
                        @if ($turnSteps->isNotEmpty())
                            {{-- Review mode shows the chat compact: steps fold into one line. --}}
                            <div class="wf-turn-summary">{{ __('workspaces.review.turn_summary', ['steps' => trans_choice('workspaces.review.steps_count', $turnSteps->count(), ['count' => $turnSteps->count()]), 'files' => trans_choice('workspaces.tray.files', $turnFiles, ['count' => $turnFiles])]) }}</div>
                        @endif
                        @foreach ($block['items'] as $item)
                            @if ($item['kind'] === 'steps')
                                @include('livewire.workspace.partials.steps', ['rows' => $item['rows'], 'running' => $block['running'] && $loop->last])
                            @else
                                @php $event = $item['event']; $p = $event->payload; @endphp
                                @switch ($event->type)
                                    @case (TaskEventType::AssistantMessage)
                                        <div class="wf-md ws-md prose" wire:key="ev-{{ $event->id }}">{{ Fmt::markdown((string) ($p['text'] ?? '')) }}</div>
                                        @break

                                    @case (TaskEventType::Thinking)
                                        <div class="wf-think" wire:key="ev-{{ $event->id }}" x-data="{ open: false }">
                                            <button type="button" class="wf-think-toggle" x-on:click="open = !open" :aria-expanded="open">
                                                <x-wf.icon name="chevron-right" :size="13" :stroke="2" x-bind:class="{ 'is-open': open }" /> {{ __('workspaces.chat.thinking') }}
                                            </button>
                                            <div class="wf-think-text" x-show="open" x-cloak>{{ $p['text'] ?? '' }}</div>
                                        </div>
                                        @break

                                    @case (TaskEventType::ApprovalRequested)
                                        @php
                                            $approval = $approvals->get((string) ($p['approval_id'] ?? ''));
                                            $kind = ApprovalKind::tryFrom((string) ($p['kind'] ?? 'tool')) ?? ApprovalKind::Tool;
                                            $input = is_array($p['input'] ?? null) ? $p['input'] : [];
                                        @endphp
                                        @if ($approval)
                                            @include('livewire.workspace.partials.approval-card', ['approval' => $approval, 'kind' => $kind, 'input' => $input, 'p' => $p, 'canWrite' => $canWrite, 'event' => $event])
                                        @endif
                                        @break

                                    @case (TaskEventType::Error)
                                        <div class="wf-note-row is-error" wire:key="ev-{{ $event->id }}">
                                            <x-wf.icon name="alert" :size="15" :stroke="2" />
                                            <span>{{ $p['message'] ?? __('workspaces.events.error') }}</span>
                                        </div>
                                        @break

                                    @case (TaskEventType::UsageLimit)
                                        <div class="wf-note-row is-warn" wire:key="ev-{{ $event->id }}">
                                            @svg('heroicon-o-clock')
                                            <span>{{ __('workspaces.task.usage_limit', ['agent' => $task->agentCredential?->name ?? '', 'time' => isset($p['resets_at']) ? \Illuminate\Support\Carbon::parse($p['resets_at'])->diffForHumans() : __('workspaces.task.usage_limit_unknown')]) }}</span>
                                        </div>
                                        @break

                                    @case (TaskEventType::Notice)
                                        <div class="ws-notice wf-notice" wire:key="ev-{{ $event->id }}">
                                            {{ match ($p['kind'] ?? '') {
                                                'interrupted' => __('workspaces.events.interrupted', ['name' => $p['author_name'] ?? '']),
                                                'permission_mode' => __('workspaces.events.permission_mode', ['name' => $p['author_name'] ?? '', 'mode' => PermissionMode::tryFrom((string) ($p['mode'] ?? ''))?->label() ?? '']),
                                                'model' => __('workspaces.events.model', ['name' => $p['author_name'] ?? '', 'model' => $p['model'] ?? '']),
                                                'shipped' => __('workspaces.events.shipped', ['name' => $p['author_name'] ?? '']),
                                                'restored' => __('workspaces.events.restored', ['name' => $p['author_name'] ?? '', 'excerpt' => $p['excerpt'] ?? '']),
                                                'checkpoint' => __('workspaces.events.checkpoint', ['name' => $p['author_name'] ?? '', 'label' => ($p['label'] ?? '') !== '' ? $p['label'] : '—']),
                                                'prepare_step' => in_array($p['step'] ?? '', ['worktree', 'setup'], true) ? __('workspaces.events.prepare_step.'.$p['step']) : '',
                                                'prepared' => ($p['setup'] ?? '') === 'failed' ? __('workspaces.events.prepared_setup_failed') : __('workspaces.events.prepared'),
                                                'prepare_failed' => __('workspaces.events.prepare_failed', ['message' => $p['message'] ?? '']),
                                                'invited' => __('workspaces.events.invited', ['name' => $p['author_name'] ?? '', 'member' => $p['member_name'] ?? '']),
                                                'uninvited' => __('workspaces.events.uninvited', ['name' => $p['author_name'] ?? '', 'member' => $p['member_name'] ?? '']),
                                                'transferred' => __('workspaces.events.transferred', ['name' => $p['author_name'] ?? '', 'owner' => $p['owner_name'] ?? '']),
                                                'cancelled' => __('workspaces.events.cancelled', ['name' => $p['author_name'] ?? '']),
                                                'ticket_linked' => __('workspaces.events.ticket_linked', ['name' => $p['author_name'] ?? '', 'ticket' => $p['ticket'] ?? '']),
                                                'ticket_created' => __('workspaces.events.ticket_created', ['name' => $p['author_name'] ?? '', 'ticket' => $p['ticket'] ?? '']),
                                                'ticket_comment' => __('workspaces.events.ticket_comment', ['name' => $p['author_name'] ?? '']),
                                                'pr_ready', 'auto_merge_on', 'auto_merge_off' => __('workspaces.events.'.$p['kind'], ['name' => $p['author_name'] ?? '']),
                                                'auto_merge_unavailable', 'pr_checks_failed', 'pr_checks_passed', 'pr_changes_requested', 'pr_approved', 'pr_merged', 'pr_closed' => __('workspaces.events.'.$p['kind']),
                                                'setup_testing', 'setup_passed', 'setup_failed' => __('workspaces.events.'.$p['kind']),
                                                default => '',
                                            } }}
                                            @if (in_array($p['kind'] ?? '', ['setup_passed', 'setup_failed'], true) && ! empty($p['steps']))
                                                <ul class="wf-setup-steps">
                                                    @foreach ($p['steps'] as $step)
                                                        <li @class(['is-ok' => $step['ok'], 'is-fail' => ! $step['ok']])>{{ $step['ok'] ? '✓' : '✗' }} {{ __('workspaces.setup.steps.'.$step['step']) }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @if (($p['kind'] ?? '') === 'prepare_failed' && $canWrite && $task->status === \App\Enums\TaskStatus::Preparing)
                                                <button type="button" class="wf-link" wire:click="retryPreparation">{{ __('workspaces.events.prepare_retry') }}</button>
                                            @endif
                                            @if (($p['kind'] ?? '') === 'ticket_comment')
                                                <blockquote class="wf-ticket-comment">{{ $p['body'] ?? '' }}@if (! empty($p['url'])) <a class="wf-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">↗</a>@endif</blockquote>
                                            @endif
                                            @if (str_starts_with((string) ($p['kind'] ?? ''), 'pr_') && ! empty($p['url']))
                                                <a class="wf-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">↗</a>
                                            @endif
                                            @if (($p['kind'] ?? '') === 'ticket_created' && ! empty($p['url']))
                                                <a class="wf-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['url'] }}</a>
                                            @endif
                                            @if (($p['kind'] ?? '') === 'prepared' && ($p['log'] ?? '') !== '')
                                                <details class="wf-notice-log">
                                                    <summary>{{ __('workspaces.events.prepared_log') }}</summary>
                                                    <pre class="wf-log mono">{{ $p['log'] }}</pre>
                                                </details>
                                            @endif
                                            @if (in_array($p['kind'] ?? '', ['restored', 'checkpoint'], true) && $canWrite && ! empty($p['checkpoint_id']))
                                                <button type="button" class="wf-link" wire:click="previewRestore('{{ $p['checkpoint_id'] }}')">
                                                    {{ ($p['kind'] ?? '') === 'restored' ? __('workspaces.checkpoints.undo_restore') : __('workspaces.checkpoints.reset_here') }}
                                                </button>
                                            @endif
                                            @if (($p['kind'] ?? '') === 'shipped' && ($p['url'] ?? '') !== '')
                                                <a class="wf-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['url'] }}</a>
                                            @endif
                                        </div>
                                        @break

                                    @case (TaskEventType::ApprovalResolved)
                                        <div class="ws-notice wf-notice" wire:key="ev-{{ $event->id }}">{{ __('workspaces.approval.cancelled') }}</div>
                                        @break
                                @endswitch
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach

            {{-- Live (not yet persisted) output, fed by the websocket. Before the
                 first event of a new turn arrives, it carries the turn head. --}}
            <div class="wf-turn wf-live" x-show="live || thinking || (status === 'running' && @js(($timeline[array_key_last($timeline) ?? 0]['kind'] ?? 'user') === 'user'))" x-cloak>
                @if (($timeline[array_key_last($timeline) ?? 0]['kind'] ?? 'user') === 'user')
                    <div class="wf-msg-meta"><span class="wf-claude-badge" aria-hidden="true">C</span> <span>{{ __('workspaces.events.claude') }} · {{ __('workspaces.task.working') }}</span></div>
                @endif
                <div class="wf-think-text" x-show="thinking && !live" x-text="thinking.slice(-400)"></div>
                <div class="wf-msg-text" x-text="live"></div>
            </div>

            <div class="wf-working is-wait" x-show="status === 'waiting_input'" x-cloak>
                <span class="wf-dot is-waiting" aria-hidden="true"></span> {{ __('workspaces.task.waiting') }}
            </div>
        </div>

        @if (TaskChat::canResume($task) && $canWrite)
            <div class="ws-alert ws-alert-warn">
                <span>{{ __('workspaces.task.session_ended') }}</span>
                <button type="button" class="btn btn-secondary btn-sm" wire:click="resume">{{ __('workspaces.task.resume') }}</button>
            </div>
        @endif

        {{-- Reset to a checkpoint --}}
        @if ($restoreId !== null)
            <div class="ws-restore" role="dialog" aria-modal="true" aria-labelledby="ws-restore-title" wire:key="restore-{{ $restoreId }}">
                <div class="ws-restore-card">
                    <h3 id="ws-restore-title">{{ __('workspaces.checkpoints.confirm_title') }}</h3>
                    <p class="muted">{{ __('workspaces.checkpoints.confirm_body') }}</p>
                    @if ($restoreCommitsSince)
                        <p class="t-warn">{{ __('workspaces.checkpoints.commits_since') }}</p>
                    @endif
                    @if (trim($restoreDiff) === '')
                        <p class="muted">{{ __('workspaces.checkpoints.no_changes') }}</p>
                    @else
                        <pre class="ws-restore-diff">{{ $restoreDiff }}</pre>
                        @if ($restoreTruncated)
                            <p class="muted">{{ __('workspaces.checkpoints.truncated') }}</p>
                        @endif
                    @endif
                    <div class="ws-row">
                        <span class="ws-spacer"></span>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelRestore">{{ __('workspaces.checkpoints.cancel') }}</button>
                        <button type="button" class="btn btn-danger btn-sm" wire:click="confirmRestore" wire:loading.attr="disabled" @disabled($busy)>
                            @svg('heroicon-o-arrow-uturn-left') {{ __('workspaces.checkpoints.confirm') }}
                        </button>
                    </div>
                    @if ($busy)
                        <p class="muted">{{ __('workspaces.checkpoints.busy') }}</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Change tray + composer --}}
        @if ($canWrite)
            <div class="wf-bottom">
                @if ($task !== null)
                    @livewire('workspace.change-tray', ['workspaceId' => $workspace->id, 'taskId' => $task->id], key('tray-'.$task->id))
                @endif
                <div @class(['ws-composer', 'wf-composer', 'has-review' => $reviewComments->isNotEmpty()])
                     x-on:dragover.prevent="dragging = true"
                     x-on:dragleave.prevent="dragging = false"
                     x-on:drop.prevent="dragging = false; attach($event.dataTransfer.files)"
                     :class="{ 'is-dragging': dragging }"
                     x-init="commands = @js($commands)">
                    <div class="ws-typing wf-muted" x-show="typingText()" x-text="typingText()" x-cloak></div>

                    @if ($uploads !== [])
                        <div class="ws-upload-previews">
                            @foreach ($uploads as $i => $upload)
                                <span class="ws-upload" wire:key="upload-{{ $i }}">
                                    @if ($upload->isPreviewable())
                                        <img src="{{ $upload->temporaryUrl() }}" alt="">
                                    @else
                                        <span class="ws-upload-name mono">{{ $upload->getClientOriginalName() }}</span>
                                    @endif
                                    <button type="button" wire:click="removeUpload({{ $i }})" aria-label="{{ __('workspaces.task.remove_image') }}" title="{{ __('workspaces.task.remove_image') }}"><x-wf.icon name="x" :size="12" :stroke="2" /></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @error('uploads.*') <p class="wf-note is-error">{{ $message }}</p> @enderror
                    @error('uploads') <p class="wf-note is-error">{{ $message }}</p> @enderror

                    @php
                        $activeComments = $reviewComments->filter(fn ($c) => $c->status === \App\Enums\ReviewCommentStatus::Draft);
                        $staleComments = $reviewComments->filter(fn ($c) => $c->status === \App\Enums\ReviewCommentStatus::Stale);
                    @endphp
                    @if ($reviewComments->isNotEmpty())
                        <div class="wf-chips">
                            @foreach ($reviewComments as $comment)
                                @php $isStale = $staleComments->contains('id', $comment->id); @endphp
                                <span @class(['wf-chip-ref', 'mono', 'is-stale' => $isStale]) wire:key="chip-{{ $comment->id }}" title="{{ $comment->body }}">
                                    {{ basename($comment->path) }}:{{ $comment->line_start }}{{ $comment->line_end > $comment->line_start ? '-'.$comment->line_end : '' }}
                                    <button type="button" wire:click="removeComment(@js($comment->id))" aria-label="{{ __('workspaces.review.remove_ref') }}">×</button>
                                </span>
                                @if ($isStale)<span class="wf-tag is-warn">{{ __('workspaces.review.outdated') }}</span>@endif
                            @endforeach
                        </div>
                    @endif

                    <div class="ws-suggest-wrap">
                        <ul class="ws-suggest" x-show="suggestions.length > 0" x-cloak role="listbox">
                            <template x-for="(item, i) in suggestions" :key="item.value">
                                <li :class="{ 'on': i === suggestIndex }" x-on:mousedown.prevent="choose(i)" role="option">
                                    <span class="mono" x-text="item.label"></span>
                                    <span class="muted" x-text="item.hint"></span>
                                </li>
                            </template>
                        </ul>
                        <label class="sr-only" for="wf-composer-{{ $task->id }}">{{ __('workspaces.chat.placeholder') }}</label>
                        <textarea
                            id="wf-composer-{{ $task->id }}"
                            class="wf-ta"
                            rows="2"
                            x-ref="composer"
                            wire:model="draft"
                            placeholder="{{ __('workspaces.chat.placeholder') }}"
                            :placeholder="status === 'running' ? @js(__('workspaces.chat.queued_placeholder')) : @js(__('workspaces.chat.placeholder'))"
                            x-on:keydown="onComposerKeydown($event)"
                            x-on:input="typed(); suggest()"
                            x-on:paste="pasted($event)"
                            x-on:blur="setTimeout(() => suggestions = [], 150)"
                        ></textarea>
                    </div>
                    <div class="wf-composer-bar">
                        <button type="button" class="wf-icon-btn wf-icon-btn-sm" x-on:click="$refs.file.click()" title="{{ __('workspaces.task.attach') }}" aria-label="{{ __('workspaces.task.attach') }}"><x-wf.icon name="paperclip" :size="17" /></button>
                        <input type="file" class="sr-only" tabindex="-1" aria-hidden="true" x-ref="file" accept="image/png,image/jpeg,image/gif,image/webp" multiple x-on:change="attach($event.target.files); $event.target.value = ''">
                        <select class="wf-pill" wire:change="setMode($event.target.value)" aria-label="{{ __('workspaces.chat.mode') }}">
                            @foreach ($modes as $mode)
                                @if ($mode !== PermissionMode::BypassPermissions || $isOwner)
                                    <option value="{{ $mode->value }}" @selected($task->permission_mode === $mode)>{{ $mode->label() }}</option>
                                @endif
                            @endforeach
                        </select>
                        <select class="wf-pill mono" wire:change="setModel($event.target.value)" aria-label="{{ __('workspaces.chat.model') }}">
                            @foreach ($models as $model)
                                <option value="{{ $model['value'] }}" title="{{ $model['description'] }}" @selected($task->model === $model['value'])>{{ $model['label'] }}</option>
                            @endforeach
                        </select>
                        @if ($task->permission_mode === PermissionMode::BypassPermissions)
                            <span class="wf-pill-tag is-danger" title="{{ __('workspaces.task.bypass_warning') }}"><x-wf.icon name="alert" :size="12" :stroke="2" /> {{ PermissionMode::BypassPermissions->label() }}</span>
                        @endif
                        @if ($reviewComments->isNotEmpty())
                            <span class="wf-composer-note">
                                {{ $staleComments->isNotEmpty()
                                    ? __('workspaces.review.collected_stale', ['active' => $activeComments->count(), 'stale' => $staleComments->count()])
                                    : trans_choice('workspaces.review.collected', $activeComments->count(), ['count' => $activeComments->count()]) }}<span x-show="status === 'running' || status === 'waiting_input'"> · {{ __('workspaces.review.queued_hint') }}</span>
                            </span>
                        @endif
                        <span class="wf-spacer"></span>
                        @if ((float) $task->cost_usd > 0)
                            <span class="wf-cost" title="{{ __('workspaces.task.cost') }}">{{ \App\Support\CostFormatter::usd((float) $task->cost_usd) }}</span>
                        @endif
                        @if ($activeComments->isNotEmpty())
                            <button type="button" class="wf-btn wf-btn-primary wf-send-review" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                                {{ __('workspaces.review.send_review') }} <span class="wf-count">{{ $activeComments->count() }}</span>
                            </button>
                        @endif
                        <button type="button" class="wf-btn wf-btn-soft wf-stop" wire:click="interrupt" x-show="status === 'running' || status === 'waiting_input'" x-cloak>
                            <x-wf.icon name="stop" :size="13" :stroke="2.6" /> {{ __('workspaces.task.stop') }}
                        </button>
                        <button type="button" class="wf-send" wire:click="send" wire:loading.attr="disabled" wire:target="send" x-show="status !== 'running' && status !== 'waiting_input'" @if ($activeComments->isNotEmpty()) hidden @endif aria-label="{{ __('workspaces.chat.send') }}" title="{{ __('workspaces.chat.send') }}">
                            <x-wf.icon name="arrow-up" :size="18" :stroke="2.2" />
                        </button>
                    </div>
                </div>
            </div>
        @else
            <div class="ws-viewer wf-muted wf-viewer">{{ __('workspaces.task.viewer_hint') }}</div>
        @endif
    @endif
    </div>{{-- .wf-chat-main --}}
</div>
