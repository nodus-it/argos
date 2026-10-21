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

    <section class="wf-section">
        <h2 class="wf-section-title">{{ __('workspaces.focus.environment') }}</h2>
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
    <section class="wf-section">
        <h2 class="wf-section-title">{{ __('workspaces.focus.branch') }}</h2>
        <div class="wf-card wf-branch">
            <span class="mono wf-branch-name">{{ $task->branch }}</span>
            <span class="wf-muted">→ {{ $task->base_branch }} · {{ $workspace->name }}</span>
            <div class="wf-tiles">
                <div class="wf-tile"><div class="wf-tile-num">{{ $diff['uncommitted'] ?? '–' }}</div><div class="wf-muted">{{ __('workspaces.focus.uncommitted') }}</div></div>
                <div class="wf-tile"><div class="wf-tile-num">{{ $diff['ahead'] ?? '–' }}</div><div class="wf-muted">{{ __('workspaces.focus.unpushed') }}</div></div>
            </div>
            @if ($task->pr_url || $issueLabel !== null)
                <div class="wf-branch-links">
                    @if ($task->pr_url)
                        <a class="wf-link" href="{{ $task->pr_url }}" target="_blank" rel="noopener">
                            <x-wf.icon name="git-pr" :size="14" :stroke="2" /> #{{ $task->pr_number }}@if ($task->pr_state) · {{ __('workspaces.pr_state.'.$task->pr_state) }}@endif
                        </a>
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
    <section class="wf-section wf-pr" data-testid="pr-section">
        <div class="wf-section-head">
            <h2 class="wf-section-title">{{ __('workspaces.pr.title') }} · #{{ $task->pr_number }}@if ($prState) · {{ __('workspaces.pr_state.'.$prState) }}@endif</h2>
            <button type="button" class="wf-text-btn" wire:click="prRefresh" wire:loading.attr="disabled">{{ __('workspaces.pr.refresh') }}</button>
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
            @if ($pr !== null && ($pr->reviews !== [] || $pr->comments !== []) && $canOperate && $open)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="prSendReview" wire:loading.attr="disabled">{{ __('workspaces.pr.send_review') }}</button>
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

    <section class="wf-section">
        <div class="wf-section-head">
            <h2 class="wf-section-title">{{ __('workspaces.focus.gates') }}</h2>
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

    <section class="wf-section" x-data="{ q: '', all: false }">
        <div class="wf-section-head">
            <h2 class="wf-section-title">{{ __('workspaces.focus.changed_files') }}@if ($files !== []) · {{ count($files) + count($generated) }}@endif</h2>
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
