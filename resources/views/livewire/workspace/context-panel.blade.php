@php
    /** @var \App\Models\Workspace $workspace */
    /** @var \App\Models\Task|null $task */
    /** @var array{files: array<int, array<string, mixed>>, truncated: bool, ahead: int, uncommitted: int}|null $diff */
@endphp
{{-- Context sidebar: environment, branch, quality gates, changed files.
     Re-renders when Claude finishes a turn or the gates change. --}}
<div class="ag-context-inner"
     x-on:argos-turn-completed.window="$wire.$refresh()"
     x-on:argos-workspace-changed.window="$wire.$refresh()"
     x-on:argos-gates-updated.window="$wire.$refresh()"
     @if ($gateRun?->status === \App\Enums\GateRunStatus::Running) wire:poll.10s @elseif ($prLoading) wire:poll.2s @endif>

    @if ($onboarding !== null)
        <section class="ag-section" id="ctx-setup" x-data="{ ...argosFold('setup', @js(collect($onboarding)->contains(fn (array $s): bool => $s['required'] && ! $s['done']))) }" :class="{ 'is-folded': !open }">
            <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.onboarding.section') }} · {{ collect($onboarding)->where('done', true)->count() }}/{{ count($onboarding) }}</button></h2>
            @include('livewire.workspace.partials.onboarding', ['steps' => $onboarding])
        </section>
    @endif

    <section class="ag-section" id="ctx-env" x-data="{ ...argosFold('env') }" :class="{ 'is-folded': !open }">
        <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.focus.environment') }}</button></h2>
        <div class="ag-env-grid">
            <div class="ag-env ag-env-tile">
                <div class="ag-env-row">
                    <span class="ag-env-icon"><x-ag.icon name="code" :size="16" /></span>
                    <span class="ag-env-name">VS Code</span>
                    @if ($canEdit && $running)
                        <a class="ag-btn ag-btn-sm ag-btn-soft ag-env-open" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code'] + ($task !== null ? ['task' => $task->id] : [])) }}" target="argos-code-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
                    @endif
                </div>
                <div class="ag-env-row">
                    @if (! $canEdit)
                        <span class="ag-env-state">{{ __('workspaces.focus.code_viewer') }}</span>
                    @else
                        <span @class(['ag-env-state', 'is-ok' => $running])>{{ $running ? __('workspaces.focus.code_ready') : __('workspaces.focus.code_off') }}</span>
                    @endif
                </div>
            </div>
            @if ($task !== null)
                @livewire('workspace.preview-panel', ['workspaceId' => $workspace->id, 'taskId' => $task->id], key('preview-'.$task->id))
            @endif
        </div>
    </section>

    @if ($task !== null)
    <section class="ag-section ag-pr" x-data="{ ...argosFold('branch') }" :class="{ 'is-folded': !open }">
        <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.focus.branch') }}@if ($task->pr_number !== null) & {{ __('workspaces.pr.title') }}@endif</button></h2>
        <div class="ag-card ag-branch">
            <span class="mono ag-branch-name" title="{{ $task->branch }} → {{ $task->base_branch }}">{{ $task->branch }} <span class="ag-muted">→ {{ $task->base_branch }}</span></span>
            <div class="ag-branch-stats">
                <span><strong>{{ $diff['uncommitted'] ?? '–' }}</strong> {{ __('workspaces.focus.uncommitted') }}</span>
                <span><strong>{{ $diff['ahead'] ?? '–' }}</strong> {{ __('workspaces.focus.unpushed') }}</span>
            </div>
            {{-- The pull request has its own section; without one its link sits here. --}}
            @if (($task->pr_url && $task->pr_number === null) || $issueLabel !== null)
                <div class="ag-branch-links">
                    @if ($task->pr_url && $task->pr_number === null)
                        <a class="ag-link" href="{{ $task->pr_url }}" target="_blank" rel="noopener"><x-ag.icon name="git-pr" :size="14" :stroke="2" /> {{ __('workspaces.pr.title') }}</a>
                    @endif
                    @if ($issueLabel !== null)
                        <a class="ag-link" href="{{ $task->external_ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ __('workspaces.fields.issue') }}">{{ $issueLabel }}</a>
                    @endif
                </div>
            @endif
        </div>
        @if ($task->pr_number !== null)
        @php
        $checks = $pr?->checks['state'] ?? ($task->pr_checks['state'] ?? 'none');
        $review = $pr?->reviewState ?? $task->pr_review_state;
        $prState = $pr?->state ?? $task->pr_state;
        $open = \App\Services\GitProvider\PullRequestDetails::isOpenState($prState);
        @endphp
        @php
            $platform = $workspace->platform;
            $checkIcon = match ($checks) { 'success' => 'check', 'failure' => 'x', 'pending' => 'refresh', default => null };
            $reviewIcon = match ($review) { 'approved' => 'check', 'changes_requested' => 'alert', 'commented' => 'message', default => null };
        @endphp
        <div class="ag-pr-sub" data-testid="pr-section">
            <span class="ag-pr-sub-title">
                <x-ag.icon name="git-pr" :size="14" :stroke="2" />
                @if ($task->pr_url)
                    <a class="ag-pr-title-link" href="{{ $task->pr_url }}" target="_blank" rel="noopener">{{ __('workspaces.pr.title') }} #{{ $task->pr_number }}</a>
                @else
                    <span>{{ __('workspaces.pr.title') }} #{{ $task->pr_number }}</span>
                @endif
                @if ($prState)<span @class(['ag-pr-state', 'is-'.$prState])>{{ __('workspaces.pr_state.'.$prState) }}</span>@endif
            </span>
            <span class="ag-section-actions">
                <a class="ag-pr-provider" href="{{ $workspace->repo_url }}" target="_blank" rel="noopener" title="{{ $workspace->getOwnerRepo() }}">{{ $platform->label() }}</a>
                @if ($task->pr_url)<a class="ag-icon-btn ag-icon-btn-sm" href="{{ $task->pr_url }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}" aria-label="{{ __('workspaces.pr.open_on_provider') }}"><x-ag.icon name="link" :size="13" :stroke="2" /></a>@endif
                <button type="button" class="ag-icon-btn ag-icon-btn-sm" wire:click="prRefresh" wire:loading.attr="disabled" title="{{ __('workspaces.pr.refresh') }}" aria-label="{{ __('workspaces.pr.refresh') }}"><x-ag.icon name="refresh" :size="13" :stroke="2" /></button>
            </span>
        </div>
        <div class="ag-card ag-pr-card">
            @if ($prLoading)
                <p class="ag-muted ag-note"><span class="ag-spin"></span> {{ __('workspaces.pr.loading') }}</p>
            @endif
            <div class="ag-pr-row">
                <span @class(['ag-pr-icon', 'is-pass' => $checks === 'success', 'is-fail' => $checks === 'failure', 'is-running' => $checks === 'pending']) aria-hidden="true">@if ($checkIcon)<x-ag.icon :name="$checkIcon" :size="12" :stroke="2.6" />@endif</span>
                <span class="ag-gate-name">{{ __('workspaces.pr.checks') }}</span>
                @if (! empty($pr?->checks['url'] ?? $task->pr_checks['url'] ?? null))
                    <a class="ag-link" href="{{ $pr?->checks['url'] ?? $task->pr_checks['url'] }}" target="_blank" rel="noopener">{{ __('workspaces.pr.checks_state.'.$checks) }} ↗</a>
                @else
                    <span class="ag-muted">{{ __('workspaces.pr.checks_state.'.$checks) }}</span>
                @endif
            </div>
            @if ($checks === 'failure' && $canOperate && $open)
                <button type="button" class="ag-btn ag-btn-sm ag-btn-soft" wire:click="prFixChecks" wire:loading.attr="disabled">{{ __('workspaces.pr.fix_checks') }}</button>
            @endif

            <div class="ag-pr-row">
                <span @class(['ag-pr-icon', 'is-pass' => $review === 'approved', 'is-fail' => $review === 'changes_requested', 'is-info' => $review === 'commented']) aria-hidden="true">@if ($reviewIcon)<x-ag.icon :name="$reviewIcon" :size="12" :stroke="2.4" />@endif</span>
                <span class="ag-gate-name">{{ __('workspaces.pr.reviews') }}</span>
                @if ($feedback['reviewers'] !== [])
                    <span class="ag-pr-reviewers">
                        @foreach ($feedback['reviewers'] as $reviewer)
                            <a class="ag-pr-reviewer" href="{{ $reviewer['url'] ?? $reviewer['profile'] ?? $task->pr_url }}" target="_blank" rel="noopener" title="{{ ($reviewer['user']?->name ?? $reviewer['author']).' · '.__('workspaces.pr.review_state.'.$reviewer['state']) }}">
                                <x-ag.avatar :user="$reviewer['user']" :name="$reviewer['author']" :url="$reviewer['avatar'] ?? null" />
                                @if (in_array($reviewer['state'], ['approved', 'changes_requested'], true))<span @class(['ag-pr-reviewer-badge', 'is-pass' => $reviewer['state'] === 'approved', 'is-fail' => $reviewer['state'] === 'changes_requested'])></span>@endif
                            </a>
                        @endforeach
                    </span>
                @endif
                <span class="ag-muted">{{ $review !== null ? __('workspaces.pr.review_state.'.$review) : __('workspaces.pr.no_reviews_yet') }}</span>
            </div>
            @if ($feedback['reviews'] !== [] || $feedback['comments'] !== [])
                @php $feedbackCount = count($feedback['comments']) + collect($feedback['reviews'])->filter(fn ($r) => trim($r['body']) !== '')->count(); @endphp
                <div x-data="{ ...argosFold('pr-feedback', false) }">
                <button type="button" class="ag-text-btn ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open">
                    <x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" x-bind:class="{ 'is-open': open }" />
                    {{ trans_choice('workspaces.pr.feedback_count', $feedbackCount, ['count' => $feedbackCount]) }}
                </button>
                <ul class="ag-pr-feedback" data-testid="pr-feedback" x-show="open" x-cloak>
                    @foreach ($feedback['reviews'] as $entry)
                        @if (trim($entry['body']) !== '')
                            <li class="ag-pr-fb">
                                <div class="ag-pr-fb-head">
                                    @include('livewire.workspace.partials.pr-person', ['who' => $entry])
                                    <span class="ag-pill-tag">{{ __('workspaces.pr.review_state.'.$entry['state']) }}</span>
                                    @if (! empty($entry['submitted_at']))
                                        @php $when = \Illuminate\Support\Carbon::parse($entry['submitted_at']); @endphp
                                        <a class="ag-muted ag-pr-fb-time" href="{{ $entry['url'] ?? $task->pr_url }}" target="_blank" rel="noopener" title="{{ $when->isoFormat('LLL') }}"><time datetime="{{ $entry['submitted_at'] }}">{{ $when->diffForHumans(short: true) }}</time></a>
                                    @endif
                                </div>
                                <div class="ag-md ws-md prose ag-pr-fb-body">{{ \App\Support\TaskEventFormatter::markdown($entry['body']) }}</div>
                            </li>
                        @endif
                    @endforeach
                    @foreach ($feedback['comments'] as $comment)
                        <li class="ag-pr-fb">
                            <div class="ag-pr-fb-head">
                                @include('livewire.workspace.partials.pr-person', ['who' => $comment])
                                @if (! empty($comment['created_at']))
                                    @php $when = \Illuminate\Support\Carbon::parse($comment['created_at']); @endphp
                                    <a class="ag-muted ag-pr-fb-time" href="{{ $comment['url'] ?? $task->pr_url }}" target="_blank" rel="noopener" title="{{ $when->isoFormat('LLL') }}"><time datetime="{{ $comment['created_at'] }}">{{ $when->diffForHumans(short: true) }}</time></a>
                                @endif
                                <span class="ag-spacer"></span>
                                @if (in_array($comment['id'], $collectedIds, true))<span class="ag-pill-tag" title="{{ __('workspaces.review.collected') }}"><x-ag.icon name="check" :size="11" :stroke="2.6" /> {{ __('workspaces.review.collected') }}</span>@endif
                                @if (! empty($comment['url']))<a class="ag-icon-btn ag-icon-btn-sm" href="{{ $comment['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}" aria-label="{{ __('workspaces.pr.open_on_provider') }}"><x-ag.icon name="link" :size="12" :stroke="2" /></a>@endif
                            </div>
                            @if ($comment['path'] !== null)
                                <button type="button" class="ag-pr-fb-where mono" x-on:click="$dispatch('review-open', { file: @js($comment['path']) })" title="{{ $comment['path'] }}"><x-ag.icon name="file" :size="11" :stroke="2" /> {{ basename($comment['path']).($comment['line'] !== null ? ':'.$comment['line'] : '') }}</button>
                            @else
                                <span class="ag-pr-fb-where is-general"><x-ag.icon name="message" :size="11" :stroke="2" /> {{ __('workspaces.pr.general_comment') }}</span>
                            @endif
                            <div class="ag-md ws-md prose ag-pr-fb-body">{{ \App\Support\TaskEventFormatter::markdown($comment['body']) }}</div>
                        </li>
                    @endforeach
                </ul>
                </div>
                @if ($canOperate && $open)
                    <button type="button" class="ag-btn ag-btn-sm ag-btn-soft" wire:click="prCollect" wire:loading.attr="disabled" title="{{ __('workspaces.review.collect_help') }}">{{ __('workspaces.review.collect_all') }}</button>
                @endif
            @endif

            @if ($pr?->conflicts ?? $task->pr_conflicts)
                <p class="ag-note is-warn">{{ __('workspaces.pr.conflicts', ['branch' => $task->base_branch]) }}</p>
            @elseif ($pr?->behindBase)
                <p class="ag-muted ag-note">{{ __('workspaces.pr.behind', ['branch' => $task->base_branch]) }}</p>
            @endif
            @if ((($pr?->conflicts ?? $task->pr_conflicts) || $pr?->behindBase) && $canOperate && $open)
                <button type="button" class="ag-btn ag-btn-sm ag-btn-soft" wire:click="prRebase" wire:loading.attr="disabled">{{ __('workspaces.pr.rebase') }}</button>
            @endif

            @if ($open && $canShip)
                <div class="ag-pr-actions">
                    @if ($prState === 'draft')
                        <button type="button" class="ag-btn ag-btn-sm ag-btn-primary" wire:click="prMarkReady" wire:loading.attr="disabled">{{ __('workspaces.pr.ready') }}</button>
                    @endif
                    @if ($autoMergeSupported)
                    <label class="ag-check" title="{{ __('workspaces.pr.auto_merge_help') }}">
                        <input type="checkbox" @checked($task->auto_merge) wire:change="prAutoMerge($event.target.checked)" data-testid="pr-auto-merge">
                        <span>{{ __('workspaces.pr.auto_merge') }}</span>
                    </label>
                    @endif
                </div>
            @endif
        </div>
        @endif
    </section>
    @endif


    <section class="ag-section" id="ctx-gates" x-data="{ ...argosFold('gates') }" :class="{ 'is-folded': !open }">
        <div class="ag-section-head">
            <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.focus.gates') }}</button></h2>
            @if ($canOperate && $running && $gatesLocked === null && $gates !== [])
                <button type="button" class="ag-text-btn" wire:click="runGates" wire:loading.attr="disabled" @disabled($gateRun?->status === \App\Enums\GateRunStatus::Running)>{{ __('workspaces.focus.check_all') }}</button>
            @endif
        </div>
        @if ($gates === [])
            <p class="ag-locked">{{ __('workspaces.focus.no_gates') }}</p>
        @elseif ($gatesLocked !== null)
            <p class="ag-locked" data-testid="gates-locked">{{ $gatesLocked }} <a href="{{ $settingsUrl }}" wire:navigate>{{ __('workspaces.setup.locked_action') }}</a></p>
        @endif
        <ul class="ag-card ag-gates">
            @foreach ($gates as $gate)
                <li class="ag-gate-item">
                    <button type="button" class="ag-gate" @disabled($task === null) aria-haspopup="dialog"
                            x-on:click="$dispatch('gate-detail-open', { taskId: @js($task?->id), gate: @js($gate['key']) })">
                        <span @class(['ag-gate-dot', 'is-'.$gate['state']]) aria-hidden="true"></span>
                        <span class="ag-gate-name">{{ $gate['label'] }}</span>
                        <span @class(['ag-gate-state', 'is-'.$gate['state']])>{{ __('workspaces.focus.gate_state.'.$gate['state']) }}</span>
                        <x-ag.icon name="chevron-right" :size="14" class="ag-gate-chev" />
                    </button>
                </li>
            @endforeach
        </ul>
        @if ($gateRun?->status === \App\Enums\GateRunStatus::Error && $gateRun->message)
            <p class="ag-note is-error">{{ $gateRun->message }}</p>
        @endif
        @livewire('workspace.gate-detail', ['workspaceId' => $workspace->id], key('gate-detail-'.$workspace->id))
    </section>

    <section class="ag-section" x-data="{ ...argosFold('files'), q: '', all: false }" :class="{ 'is-folded': !open }">
        <div class="ag-section-head">
            <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.focus.changed_files') }}@if ($files !== []) · {{ count($files) + count($generated) }}@endif</button></h2>
            @if (count($files) > 1)
                <button type="button" class="ag-text-btn" x-on:click="window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))">{{ __('workspaces.tray.review') }}</button>
            @endif
        </div>
        @if (! $running)
            <p class="ag-muted ag-note">{{ __('workspaces.focus.unavailable') }}</p>
        @elseif ($files === [] && $generated === [])
            <p class="ag-muted ag-note">{{ __('workspaces.focus.no_changes') }}</p>
        @else
            @if (count($files) > 10)
                <input type="search" class="ag-input ag-file-filter" x-model="q" placeholder="{{ __('workspaces.focus.filter_files') }}" aria-label="{{ __('workspaces.focus.filter_files') }}">
            @endif
            <ul class="ag-files mono">
                @foreach ($files as $i => $file)
                    <li wire:key="cf-{{ md5($file['path']) }}" data-path="{{ mb_strtolower($file['path']) }}"
                        x-show="q !== '' ? $el.dataset.path.includes(q.toLowerCase()) : (all || {{ $i }} < 10)">
                        <button type="button" class="ag-file" title="{{ $file['path'] }}" x-on:click="$dispatch('review-open', { file: @js($file['path']) })">
                            <span @class(['ag-file-status', 'is-'.strtolower($file['status'])])>{{ $file['status'] }}</span>
                            <span class="ag-file-name">{{ $file['name'] }}@if ($file['dir'] !== '')<span class="ag-file-dir"> {{ $file['dir'] }}</span>@endif</span>
                            @if ($file['additions'] > 0)<span class="ag-add">+{{ $file['additions'] }}</span>@endif
                            @if ($file['deletions'] > 0)<span class="ag-del">−{{ $file['deletions'] }}</span>@endif
                        </button>
                    </li>
                @endforeach
            </ul>
            @if (count($files) > 10)
                <button type="button" class="ag-text-btn ag-muted" x-show="q === ''" x-on:click="all = !all"
                        x-text="all ? @js(__('workspaces.focus.show_less')) : @js(__('workspaces.focus.show_all', ['count' => count($files)]))"></button>
            @endif
            @if ($generated !== [])
                <p class="ag-muted ag-note ag-generated" title="{{ implode(', ', array_column($generated, 'path')) }}">
                    {{ trans_choice('workspaces.focus.generated', count($generated), ['count' => count($generated), 'files' => implode(', ', array_map('basename', array_slice(array_column($generated, 'path'), 0, 3)))]) }}
                </p>
            @endif
        @endif
    </section>
</div>
