@php
    /** @var array{files: array<int, array<string, mixed>>, truncated: bool, ahead: int, uncommitted: int}|null $diff */
    $additions = $diff ? array_sum(array_column($diff['files'], 'additions')) : 0;
    $deletions = $diff ? array_sum(array_column($diff['files'], 'deletions')) : 0;
@endphp

<div class="ws-review"
     x-data="{
        comments: [],
        draft: null,
        stale: false,
        open(path, line) { this.draft = { path, line, text: '' }; },
        add() { if (this.draft && this.draft.text.trim() !== '') { this.comments.push({ ...this.draft }); } this.draft = null; },
        remove(i) { this.comments.splice(i, 1); },
        async send() { if (await $wire.sendComments(this.comments)) { this.comments = []; } },
     }"
     x-intersect.once="$wire.load()"
     x-init="window.addEventListener('argos-turn-completed', () => { stale = true; })"
     x-on:argos-gates-updated.window="$wire.$refresh()">

    <div class="ws-frame-bar">
        @if ($diff)
            <span class="muted">
                {{ __('workspaces.review.summary', ['files' => count($diff['files']), 'ahead' => $diff['ahead'], 'uncommitted' => $diff['uncommitted']]) }}
                <span class="stat-add">+{{ $additions }}</span> <span class="stat-del">−{{ $deletions }}</span>
            </span>
        @endif
        <span class="ws-spacer"></span>
        <span class="badge badge-running" x-show="stale" x-cloak>{{ __('workspaces.review.stale') }}</span>
        <button type="button" class="btn btn-ghost btn-sm" x-on:click="stale = false; $wire.$refresh()" wire:loading.attr="disabled">
            @svg('heroicon-o-arrow-path') {{ __('workspaces.review.refresh') }}
        </button>
    </div>

    {{-- Quality gates --}}
    <section class="ws-gates" @if ($gateRun?->status === \App\Enums\GateRunStatus::Running) wire:poll.10s @endif>
        <div class="ws-row">
            <strong>{{ __('workspaces.gates.title') }}</strong>
            @if ($gateRun && $gateRun->status !== \App\Enums\GateRunStatus::Running)
                <span class="badge {{ $gateRun->status === \App\Enums\GateRunStatus::Passed ? 'badge-success' : 'badge-failed' }}">{{ $gateRun->status->label() }}</span>
                @if ($gateRun->gates)
                    <span class="muted">{{ __('workspaces.gates.result', ['passed' => $gateRun->passedCount(), 'total' => count($gateRun->ranGates())]) }}</span>
                @endif
            @endif
            <span class="ws-spacer"></span>
            @if ($canWrite && $workspace->status === \App\Enums\WorkspaceStatus::Running)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="runGates" wire:loading.attr="disabled" @disabled($gateRun?->status === \App\Enums\GateRunStatus::Running)>
                    @svg('heroicon-o-shield-check') {{ __('workspaces.gates.run') }}
                </button>
            @endif
        </div>

        @if (! $gateRun)
            <p class="muted">{{ __('workspaces.gates.never') }}</p>
        @elseif ($gateRun->status === \App\Enums\GateRunStatus::Running)
            <p class="muted">{{ __('workspaces.gates.running') }}</p>
        @elseif ($gateRun->status === \App\Enums\GateRunStatus::Error)
            <p class="t-err">{{ $gateRun->message }}</p>
        @else
            <p class="muted">{{ __('workspaces.gates.baseline_'.($gateRun->baseline ?? 'missing')) }}</p>
            <ul class="ws-gate-list">
                @foreach ($gateRun->gates ?? [] as $gate => $result)
                    <li x-data="{ open: {{ $result === 'fail' ? 'true' : 'false' }} }">
                        <div class="ws-row">
                            <span class="badge {{ ['pass' => 'badge-success', 'fail' => 'badge-failed'][$result] ?? 'badge-draft' }}">{{ __('workspaces.gates.'.$result) }}</span>
                            <span class="mono">{{ $gate }}</span>
                            <span class="ws-spacer"></span>
                            @if (($gateRun->logs[$gate] ?? '') !== '')
                                <button type="button" class="btn btn-ghost btn-sm" x-on:click="open = ! open">{{ __('workspaces.gates.log') }}</button>
                            @endif
                            @if ($result === 'fail' && $canWrite)
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="sendGateFix('{{ $gate }}')">@svg('heroicon-o-sparkles') {{ __('workspaces.gates.send_fix') }}</button>
                            @endif
                        </div>
                        @if (($gateRun->logs[$gate] ?? '') !== '')
                            <pre class="ws-gate-log" x-show="open" x-cloak>{{ $gateRun->logs[$gate] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Collected comments --}}
    @if ($canWrite)
        <div class="ws-review-comments" x-show="comments.length > 0" x-cloak>
            <template x-for="(c, i) in comments" :key="i">
                <div class="ws-review-comment">
                    <span class="mono" x-text="c.path + ':' + c.line"></span>
                    <span x-text="c.text"></span>
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="remove(i)">@svg('heroicon-o-x-mark')</button>
                </div>
            </template>
            <div class="ws-row">
                @if ($threads->count() > 1)
                    <select class="ws-select ws-select-sm" wire:model="threadId" aria-label="{{ __('workspaces.thread.title') }}">
                        @foreach ($threads as $t)
                            <option value="{{ $t->id }}">{{ $t->title }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" class="btn btn-primary btn-sm" x-on:click="send()">
                    @svg('heroicon-o-paper-airplane') <span x-text="@js(__('workspaces.review.send')) + ' (' + comments.length + ')'"></span>
                </button>
            </div>
        </div>
    @endif

    @if ($workspace->status !== \App\Enums\WorkspaceStatus::Running)
        <div class="ws-tab-empty muted">{{ __('workspaces.tabs.not_running') }}</div>
    @elseif ($error)
        <div class="ws-alert ws-alert-error">@svg('heroicon-o-exclamation-triangle') <span>{{ $error }}</span></div>
    @elseif ($diff === null)
        <div class="ws-tab-empty"><span class="ws-dot"></span></div>
    @elseif ($diff['files'] === [])
        <div class="ws-tab-empty muted">{{ __('workspaces.review.no_changes', ['branch' => $workspace->base_branch]) }}</div>
    @else
        <div class="ws-review-files">
            @if ($diff['truncated'])
                <div class="ws-alert ws-alert-warn">{{ __('workspaces.review.truncated') }}</div>
            @endif
            @foreach ($diff['files'] as $file)
                @php $path = $file['to_path'] ?: $file['from_path']; @endphp
                <div class="diff" wire:key="file-{{ md5($path) }}">
                    <div class="diff-file">
                        @svg('heroicon-o-document-text')
                        @if ($file['is_new'] ?? false) <span class="chip">{{ __('tasks.view.diff.badge_new') }}</span>
                        @elseif ($file['is_deleted'] ?? false) <span class="chip">{{ __('tasks.view.diff.badge_deleted') }}</span>
                        @endif
                        <span class="path">{{ $path }}</span>
                        <span><span class="stat-add">+{{ $file['additions'] }}</span> <span class="stat-del">−{{ $file['deletions'] }}</span></span>
                    </div>
                    <div class="diff-body">
                        @foreach ($file['hunks'] as $hunk)
                            <div class="diff-row hunk"><span class="gut"><span></span><span></span></span><span class="code">{{ $hunk['header'] }}</span></div>
                            @foreach ($hunk['lines'] as $line)
                                @php $lineNo = $line['new_num'] ?? $line['old_num']; @endphp
                                <div @class(['diff-row', 'add' => $line['type'] === 'add', 'del' => $line['type'] === 'del', 'ws-commentable' => $canWrite])
                                     @if ($canWrite) x-on:click="open(@js($path), @js($lineNo))" title="{{ __('workspaces.review.comment') }}" @endif>
                                    <span class="gut"><span>{{ $line['old_num'] ?? '' }}</span><span>{{ $line['new_num'] ?? '' }}</span></span>
                                    <span class="code">{{ $line['type'] === 'add' ? '+' : ($line['type'] === 'del' ? '-' : ' ') }}{{ $line['text'] }}</span>
                                </div>
                                <template x-if="draft && draft.path === @js($path) && draft.line === @js($lineNo)">
                                    <div class="ws-review-draft" x-on:click.stop>
                                        <textarea class="respond-ta" rows="2" x-model="draft.text" x-init="$nextTick(() => $el.focus())"
                                                  placeholder="{{ __('workspaces.review.comment_placeholder') }}"
                                                  x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); add(); }"></textarea>
                                        <div class="ws-row">
                                            <button type="button" class="btn btn-secondary btn-sm" x-on:click="add()">{{ __('workspaces.review.add') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="draft = null">{{ __('workspaces.review.cancel') }}</button>
                                        </div>
                                    </div>
                                </template>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
