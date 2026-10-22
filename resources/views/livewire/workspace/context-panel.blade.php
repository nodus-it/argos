@php
    /** @var \App\Models\Workspace $workspace */
    /** @var \App\Models\Task|null $task */
    /** @var array{files: array<int, array<string, mixed>>, truncated: bool, ahead: int, uncommitted: int}|null $diff */
@endphp
{{-- Context sidebar: environment, branch, quality gates, changed files.
     Re-renders when Claude finishes a turn or the gates change. --}}
<div class="wf-context-inner"
     x-on:argos-turn-completed.window="$wire.$refresh()"
     x-on:argos-workspace-changed.window="$wire.$refresh()"
     x-on:argos-gates-updated.window="$wire.$refresh()"
     @if ($gateRun?->status === \App\Enums\GateRunStatus::Running) wire:poll.10s @endif>

    <section class="wf-section" x-data="{ ...argosFold('env') }" :class="{ 'is-folded': !open }">
        <h2 class="wf-section-h"><button type="button" class="wf-section-title wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" />{{ __('workspaces.focus.environment') }}</button></h2>
        <div class="wf-env">
            <div class="wf-env-row">
                <span class="wf-env-icon"><x-wf.icon name="code" :size="18" /></span>
                <span class="wf-env-text">
                    <span class="wf-env-name">VS Code</span>
                    @if (! $canEdit)
                        <span class="wf-env-state">{{ __('workspaces.focus.code_viewer') }}</span>
                    @else
                        <span @class(['wf-env-state', 'is-ok' => $running])>{{ $running ? __('workspaces.focus.code_ready') : __('workspaces.focus.code_off') }}</span>
                    @endif
                </span>
                @if ($canEdit && $running)
                    <a class="wf-btn wf-btn-sm wf-btn-soft" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code'] + ($task !== null ? ['task' => $task->id] : [])) }}" target="argos-code-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
                @endif
            </div>
        </div>
        @if ($task !== null)
            @livewire('workspace.preview-panel', ['workspaceId' => $workspace->id, 'taskId' => $task->id], key('preview-'.$task->id))
        @endif
    </section>

    @if ($task !== null)
    <section class="wf-section" x-data="{ ...argosFold('branch') }" :class="{ 'is-folded': !open }">
        <h2 class="wf-section-h"><button type="button" class="wf-section-title wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" />{{ __('workspaces.focus.branch') }}</button></h2>
        <div class="wf-card wf-branch">
            <span class="mono wf-branch-name" title="{{ $task->branch }} → {{ $task->base_branch }}">{{ $task->branch }} <span class="wf-muted">→ {{ $task->base_branch }}</span></span>
            <div class="wf-branch-stats">
                <span><strong>{{ $diff['uncommitted'] ?? '–' }}</strong> {{ __('workspaces.focus.uncommitted') }}</span>
                <span><strong>{{ $diff['ahead'] ?? '–' }}</strong> {{ __('workspaces.focus.unpushed') }}</span>
            </div>
            {{-- The pull request has its own section; without one its link sits here. --}}
            @if (($task->pr_url && $task->pr_number === null) || $issueLabel !== null)
                <div class="wf-branch-links">
                    @if ($task->pr_url && $task->pr_number === null)
                        <a class="wf-link" href="{{ $task->pr_url }}" target="_blank" rel="noopener"><x-wf.icon name="git-pr" :size="14" :stroke="2" /> {{ __('workspaces.pr.title') }}</a>
                    @endif
                    @if ($issueLabel !== null)
                        <a class="wf-link" href="{{ $task->external_ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ __('workspaces.fields.issue') }}">{{ $issueLabel }}</a>
                    @endif
                </div>
            @endif
        </div>
    </section>
    @endif

    @if ($task?->pr_number !== null)
    @php
        $checks = $pr?->checks['state'] ?? ($task->pr_checks['state'] ?? 'none');
        $review = $pr?->reviewState ?? $task->pr_review_state;
        $prState = $pr?->state ?? $task->pr_state;
        $open = in_array($prState, ['open', 'draft'], true);
    @endphp
    <section class="wf-section wf-pr" data-testid="pr-section" x-data="{ ...argosFold('pr') }" :class="{ 'is-folded': !open }">
        <div class="wf-section-head">
            <h2 class="wf-section-h"><button type="button" class="wf-section-title wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" />{{ __('workspaces.pr.title') }} · #{{ $task->pr_number }}@if ($prState) · {{ __('workspaces.pr_state.'.$prState) }}@endif</button></h2>
            <span class="wf-section-actions">
                @if ($task->pr_url)<a class="wf-text-btn" href="{{ $task->pr_url }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}">↗</a>@endif
                <button type="button" class="wf-text-btn" wire:click="prRefresh" wire:loading.attr="disabled" title="{{ __('workspaces.pr.refresh') }}"><x-wf.icon name="refresh" :size="13" :stroke="2" label="{{ __('workspaces.pr.refresh') }}" /></button>
            </span>
        </div>
        <div class="wf-card wf-pr-card">
            <div class="wf-pr-row">
                <span @class(['wf-gate-dot', 'is-pass' => $checks === 'success', 'is-fail' => $checks === 'failure', 'is-running' => $checks === 'pending', 'is-open' => $checks === 'none']) aria-hidden="true"></span>
                <span class="wf-gate-name">{{ __('workspaces.pr.checks') }}</span>
                @if (! empty($pr?->checks['url'] ?? $task->pr_checks['url'] ?? null))
                    <a class="wf-link" href="{{ $pr?->checks['url'] ?? $task->pr_checks['url'] }}" target="_blank" rel="noopener">{{ __('workspaces.pr.checks_state.'.$checks) }} ↗</a>
                @else
                    <span class="wf-muted">{{ __('workspaces.pr.checks_state.'.$checks) }}</span>
                @endif
            </div>
            @if ($checks === 'failure' && $canOperate && $open)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="prFixChecks" wire:loading.attr="disabled">{{ __('workspaces.pr.fix_checks') }}</button>
            @endif

            <div class="wf-pr-row">
                <span @class(['wf-gate-dot', 'is-pass' => $review === 'approved', 'is-fail' => $review === 'changes_requested', 'is-open' => ! in_array($review, ['approved', 'changes_requested'], true)]) aria-hidden="true"></span>
                <span class="wf-gate-name">{{ __('workspaces.pr.reviews') }}</span>
                <span class="wf-muted">{{ $review !== null ? __('workspaces.pr.review_state.'.$review) : __('workspaces.pr.no_reviews_yet') }}</span>
            </div>
            @if ($feedback['reviews'] !== [] || $feedback['comments'] !== [])
                @php $feedbackCount = count($feedback['comments']) + collect($feedback['reviews'])->filter(fn ($r) => trim($r['body']) !== '')->count(); @endphp
                <div x-data="{ ...argosFold('pr-feedback', false) }">
                <button type="button" class="wf-text-btn wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open">
                    <x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" x-bind:class="{ 'is-open': open }" />
                    {{ trans_choice('workspaces.pr.feedback_count', $feedbackCount, ['count' => $feedbackCount]) }}
                </button>
                <ul class="wf-pr-feedback" data-testid="pr-feedback" x-show="open" x-cloak>
                    @foreach ($feedback['reviews'] as $review)
                        @if (trim($review['body']) !== '')
                            <li class="wf-pr-fb">
                                <div class="wf-pr-fb-head"><strong>{{ $review['author'] }}</strong> <span class="wf-muted">· {{ __('workspaces.pr.review_state.'.$review['state']) }}</span></div>
                                <div class="wf-md ws-md prose wf-pr-fb-body">{{ \App\Support\TaskEventFormatter::markdown($review['body']) }}</div>
                            </li>
                        @endif
                    @endforeach
                    @foreach ($feedback['comments'] as $comment)
                        <li class="wf-pr-fb">
                            <div class="wf-pr-fb-head">
                                <strong>{{ $comment['author'] }}</strong>
                                @if ($comment['path'] !== null)
                                    <button type="button" class="wf-link mono wf-pr-fb-where" x-on:click="$dispatch('review-open', { file: @js($comment['path']) })">{{ basename($comment['path']).($comment['line'] !== null ? ':'.$comment['line'] : '') }}</button>
                                @else
                                    <span class="wf-muted">· {{ __('workspaces.pr.general_comment') }}</span>
                                @endif
                                @if (! empty($comment['url']))<a class="wf-link" href="{{ $comment['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}">↗</a>@endif
                                @if (in_array($comment['id'], $collectedIds, true))<span class="wf-pill-tag">{{ __('workspaces.review.collected') }}</span>@endif
                            </div>
                            <div class="wf-md ws-md prose wf-pr-fb-body">{{ \App\Support\TaskEventFormatter::markdown($comment['body']) }}</div>
                        </li>
                    @endforeach
                </ul>
                </div>
                @if ($canOperate && $open)
                    <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="prCollect" wire:loading.attr="disabled" title="{{ __('workspaces.review.collect_help') }}">{{ __('workspaces.review.collect_all') }}</button>
                @endif
            @endif

            @if ($pr?->conflicts ?? $task->pr_conflicts)
                <p class="wf-note is-warn">{{ __('workspaces.pr.conflicts', ['branch' => $task->base_branch]) }}</p>
            @elseif ($pr?->behindBase)
                <p class="wf-muted wf-note">{{ __('workspaces.pr.behind', ['branch' => $task->base_branch]) }}</p>
            @endif
            @if ((($pr?->conflicts ?? $task->pr_conflicts) || $pr?->behindBase) && $canOperate && $open)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="prRebase" wire:loading.attr="disabled">{{ __('workspaces.pr.rebase') }}</button>
            @endif

            @if ($open && $canShip)
                <div class="wf-pr-actions">
                    @if ($prState === 'draft')
                        <button type="button" class="wf-btn wf-btn-sm wf-btn-primary" wire:click="prMarkReady" wire:loading.attr="disabled">{{ __('workspaces.pr.ready') }}</button>
                    @endif
                    @if ($autoMergeSupported)
                    <label class="wf-check" title="{{ __('workspaces.pr.auto_merge_help') }}">
                        <input type="checkbox" @checked($task->auto_merge) wire:change="prAutoMerge($event.target.checked)" data-testid="pr-auto-merge">
                        <span>{{ __('workspaces.pr.auto_merge') }}</span>
                    </label>
                    @endif
                </div>
            @endif
        </div>
    </section>
    @endif

    <section class="wf-section" x-data="{ ...argosFold('gates') }" :class="{ 'is-folded': !open }">
        <div class="wf-section-head">
            <h2 class="wf-section-h"><button type="button" class="wf-section-title wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" />{{ __('workspaces.focus.gates') }}</button></h2>
            @if ($canOperate && $running)
                <button type="button" class="wf-text-btn" wire:click="runGates" wire:loading.attr="disabled" @disabled($gateRun?->status === \App\Enums\GateRunStatus::Running)>{{ __('workspaces.focus.check_all') }}</button>
            @endif
        </div>
        <ul class="wf-card wf-gates">
            @foreach ($gates as $gate)
                <li class="wf-gate-item">
                    <button type="button" class="wf-gate" @disabled($task === null) aria-haspopup="dialog"
                            x-on:click="$dispatch('gate-detail-open', { taskId: @js($task?->id), gate: @js($gate['key']) })">
                        <span @class(['wf-gate-dot', 'is-'.$gate['state']]) aria-hidden="true"></span>
                        <span class="wf-gate-name">{{ $gate['label'] }}</span>
                        <span @class(['wf-gate-state', 'is-'.$gate['state']])>{{ __('workspaces.focus.gate_state.'.$gate['state']) }}</span>
                        <x-wf.icon name="chevron-right" :size="14" class="wf-gate-chev" />
                    </button>
                </li>
            @endforeach
        </ul>
        @if ($gateRun?->status === \App\Enums\GateRunStatus::Error && $gateRun->message)
            <p class="wf-note is-error">{{ $gateRun->message }}</p>
        @endif
        @livewire('workspace.gate-detail', ['workspaceId' => $workspace->id], key('gate-detail-'.$workspace->id))
    </section>

    <section class="wf-section" x-data="{ ...argosFold('files'), q: '', all: false }" :class="{ 'is-folded': !open }">
        <div class="wf-section-head">
            <h2 class="wf-section-h"><button type="button" class="wf-section-title wf-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-wf.icon name="chevron-right" :size="12" :stroke="2.4" class="wf-fold-chev" />{{ __('workspaces.focus.changed_files') }}@if ($files !== []) · {{ count($files) + count($generated) }}@endif</button></h2>
            @if (count($files) > 1)
                <button type="button" class="wf-text-btn" x-on:click="window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))">{{ __('workspaces.tray.review') }}</button>
            @endif
        </div>
        @if (! $running)
            <p class="wf-muted wf-note">{{ __('workspaces.focus.unavailable') }}</p>
        @elseif ($files === [] && $generated === [])
            <p class="wf-muted wf-note">{{ __('workspaces.focus.no_changes') }}</p>
        @else
            @if (count($files) > 10)
                <input type="search" class="wf-input wf-file-filter" x-model="q" placeholder="{{ __('workspaces.focus.filter_files') }}" aria-label="{{ __('workspaces.focus.filter_files') }}">
            @endif
            <ul class="wf-files mono">
                @foreach ($files as $i => $file)
                    <li wire:key="cf-{{ md5($file['path']) }}" data-path="{{ mb_strtolower($file['path']) }}"
                        x-show="q !== '' ? $el.dataset.path.includes(q.toLowerCase()) : (all || {{ $i }} < 10)">
                        <button type="button" class="wf-file" title="{{ $file['path'] }}" x-on:click="$dispatch('review-open', { file: @js($file['path']) })">
                            <span @class(['wf-file-status', 'is-'.strtolower($file['status'])])>{{ $file['status'] }}</span>
                            <span class="wf-file-name">{{ $file['name'] }}@if ($file['dir'] !== '')<span class="wf-file-dir"> {{ $file['dir'] }}</span>@endif</span>
                            @if ($file['additions'] > 0)<span class="wf-add">+{{ $file['additions'] }}</span>@endif
                            @if ($file['deletions'] > 0)<span class="wf-del">−{{ $file['deletions'] }}</span>@endif
                        </button>
                    </li>
                @endforeach
            </ul>
            @if (count($files) > 10)
                <button type="button" class="wf-text-btn wf-muted" x-show="q === ''" x-on:click="all = !all"
                        x-text="all ? @js(__('workspaces.focus.show_less')) : @js(__('workspaces.focus.show_all', ['count' => count($files)]))"></button>
            @endif
            @if ($generated !== [])
                <p class="wf-muted wf-note wf-generated" title="{{ implode(', ', array_column($generated, 'path')) }}">
                    {{ trans_choice('workspaces.focus.generated', count($generated), ['count' => count($generated), 'files' => implode(', ', array_map('basename', array_slice(array_column($generated, 'path'), 0, 3)))]) }}
                </p>
            @endif
        @endif
    </section>
</div>
