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
                    <a class="wf-btn wf-btn-sm wf-btn-soft" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']) }}" target="argos-code-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
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

    <section class="wf-section">
        <div class="wf-section-head">
            <h2 class="wf-section-title">{{ __('workspaces.focus.gates') }}</h2>
            @if ($canOperate && $running)
                <button type="button" class="wf-text-btn" wire:click="runGates" wire:loading.attr="disabled" @disabled($gateRun?->status === \App\Enums\GateRunStatus::Running)>{{ __('workspaces.focus.check_all') }}</button>
            @endif
        </div>
        <ul class="wf-card wf-gates">
            @foreach ($gates as $gate)
                <li class="wf-gate-item" x-data="{ open: false }">
                    <button type="button" class="wf-gate" @if ($gate['failed'] !== []) x-on:click="open = !open" :aria-expanded="open" @else disabled @endif>
                        <span @class(['wf-gate-dot', 'is-'.$gate['state']]) aria-hidden="true"></span>
                        <span class="wf-gate-name">{{ $gate['label'] }}</span>
                        <span @class(['wf-gate-state', 'is-'.$gate['state']])>{{ __('workspaces.focus.gate_state.'.$gate['state']) }}</span>
                    </button>
                    @if ($gate['failed'] !== [])
                        <div class="wf-gate-more" x-show="open" x-cloak>
                            @if ($gate['log'] !== '')<pre class="wf-log mono">{{ $gate['log'] }}</pre>@endif
                            @if ($canOperate)
                                @foreach ($gate['failed'] as $failedGate)
                                    <button type="button" class="wf-btn wf-btn-sm wf-btn-soft" wire:click="sendGateFix('{{ $failedGate }}')">{{ __('workspaces.gates.send_fix') }}@if (count($gate['failed']) > 1) ({{ $failedGate }})@endif</button>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($gateRun?->status === \App\Enums\GateRunStatus::Error && $gateRun->message)
            <p class="wf-note is-error">{{ $gateRun->message }}</p>
        @endif
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
