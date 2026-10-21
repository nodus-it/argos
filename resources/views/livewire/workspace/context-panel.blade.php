@php
    /** @var \App\Models\Workspace $workspace */
    /** @var array{files: array<int, array<string, mixed>>, truncated: bool, ahead: int, uncommitted: int}|null $diff */
@endphp
{{-- Context sidebar: environment, branch, quality gates, changed files.
     Re-renders when Claude finishes a turn or the gates change. --}}
<div class="wf-context-inner"
     x-on:argos-turn-completed.window="$wire.$refresh()"
     x-on:argos-gates-updated.window="$wire.$refresh()"
     @if ($gateRun?->status === \App\Enums\GateRunStatus::Running) wire:poll.10s @endif>

    <section class="wf-section">
        <h2 class="wf-section-title">{{ __('workspaces.focus.environment') }}</h2>
        <div class="wf-env">
            <div class="wf-env-row">
                <span class="wf-env-icon"><x-wf.icon name="code" :size="18" /></span>
                <span class="wf-env-text">
                    <span class="wf-env-name">VS Code</span>
                    @if (! $canOperate)
                        <span class="wf-env-state">{{ __('workspaces.focus.code_viewer') }}</span>
                    @else
                        <span @class(['wf-env-state', 'is-ok' => $running])>{{ $running ? __('workspaces.focus.code_ready') : __('workspaces.focus.code_off') }}</span>
                    @endif
                </span>
                @if ($canOperate && $running)
                    <a class="wf-btn wf-btn-sm wf-btn-soft" href="{{ route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']) }}" target="argos-code-{{ $workspace->slug }}">{{ __('workspaces.tabs.open') }}</a>
                @endif
            </div>
        </div>
        @livewire('workspace.preview-panel', ['workspaceId' => $workspace->id], key('preview-'.$workspace->id))
    </section>

    <section class="wf-section">
        <h2 class="wf-section-title">{{ __('workspaces.focus.branch') }}</h2>
        <div class="wf-card wf-branch">
            <span class="mono wf-branch-name">{{ $workspace->branch }}</span>
            <span class="wf-muted">→ {{ $workspace->base_branch }} · {{ $workspace->repoProfile->name }}</span>
            <div class="wf-tiles">
                <div class="wf-tile"><div class="wf-tile-num">{{ $diff['uncommitted'] ?? '–' }}</div><div class="wf-muted">{{ __('workspaces.focus.uncommitted') }}</div></div>
                <div class="wf-tile"><div class="wf-tile-num">{{ $diff['ahead'] ?? '–' }}</div><div class="wf-muted">{{ __('workspaces.focus.unpushed') }}</div></div>
            </div>
            @if ($workspace->pr_url || $issueLabel !== null)
                <div class="wf-branch-links">
                    @if ($workspace->pr_url)
                        <a class="wf-link" href="{{ $workspace->pr_url }}" target="_blank" rel="noopener">
                            <x-wf.icon name="git-pr" :size="14" :stroke="2" /> #{{ $workspace->pr_number }}@if ($workspace->pr_state) · {{ __('workspaces.pr_state.'.$workspace->pr_state) }}@endif
                        </a>
                    @endif
                    @if ($issueLabel !== null)
                        <a class="wf-link" href="{{ $workspace->external_issue['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.fields.issue') }}">{{ $issueLabel }}</a>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="wf-section">
        <div class="wf-section-head">
            <h2 class="wf-section-title">{{ __('workspaces.focus.gates') }}</h2>
            @if ($canOperate && $running)
                <button type="button" class="wf-text-btn" wire:click="runGates" wire:loading.attr="disabled" @disabled($gateRun?->status === \App\Enums\GateRunStatus::Running)>{{ __('workspaces.focus.check_all') }}</button>
            @endif
        </div>
        <ul class="wf-card wf-gates">
            @foreach ($gates as $gate)
                <li class="wf-gate">
                    <span @class(['wf-gate-dot', 'is-'.$gate['state']]) aria-hidden="true"></span>
                    <span class="wf-gate-name">{{ $gate['label'] }}</span>
                    <span @class(['wf-gate-state', 'is-'.$gate['state']])>{{ __('workspaces.focus.gate_state.'.$gate['state']) }}</span>
                </li>
            @endforeach
        </ul>
        @if ($gateRun?->status === \App\Enums\GateRunStatus::Error && $gateRun->message)
            <p class="wf-note is-error">{{ $gateRun->message }}</p>
        @endif
    </section>

    <section class="wf-section">
        <h2 class="wf-section-title">{{ __('workspaces.focus.changed_files') }}</h2>
        @if (! $running)
            <p class="wf-muted wf-note">{{ __('workspaces.focus.unavailable') }}</p>
        @elseif ($files === [])
            <p class="wf-muted wf-note">{{ __('workspaces.focus.no_changes') }}</p>
        @else
            <ul class="wf-files mono">
                @foreach ($files as $file)
                    <li>
                        <button type="button" class="wf-file" title="{{ $file['path'] }}" x-on:click="$dispatch('review-open', { file: @js($file['path']) })">
                            <span @class(['wf-file-status', 'is-'.strtolower($file['status'])])>{{ $file['status'] }}</span>
                            <span class="wf-file-name">{{ $file['name'] }}</span>
                            @if ($file['additions'] > 0)<span class="wf-add">+{{ $file['additions'] }}</span>@endif
                            @if ($file['deletions'] > 0)<span class="wf-del">−{{ $file['deletions'] }}</span>@endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
