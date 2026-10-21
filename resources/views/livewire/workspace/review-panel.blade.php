{{-- Review mode: toolbar, file tree and the diff of one file. Opens and
     closes with the page's review state (resources/js/workspace/focus.js). --}}
<div class="wf-review" x-data="argosReview(@js(__('workspaces.review.comment_on', ['line' => '__L__'])))"
     x-on:argos-review-opened.window="$wire.open($event.detail.file)"
     x-on:argos-review-closed.window="$wire.close()"
     x-on:argos-file-changed.window="fileChanged($event.detail)"
     x-on:argos-gates-updated.window="if ($wire.active) $wire.$refresh()">
@if ($active)
    @php
        /** @var list<array<string, mixed>> $files */
        $seenCount = count($seen);
    @endphp
    <span class="hidden" wire:key="cc-{{ $commentCount }}" x-init="window.dispatchEvent(new CustomEvent('argos-review-count', { detail: {{ $commentCount }} }))"></span>
    <div class="wf-review-bar">
        <span class="wf-review-title">{{ __('workspaces.review.changes') }}</span>
        <span class="mono wf-review-stat"><span class="wf-add">+{{ $additions }}</span> <span class="wf-del">−{{ $deletions }}</span></span>
        <span class="wf-muted wf-review-seen">{{ __('workspaces.review.seen_progress', ['seen' => $seenCount, 'total' => count($files)]) }}</span>
        <span class="wf-spacer"></span>
        @if ($gates !== [])
            <span class="wf-gate-chips" role="group" aria-label="{{ __('workspaces.focus.gates') }}">
                @foreach ($gates as $gate)
                    @php $count = $gateCounts[$gate['key']] ?? 0; @endphp
                    @if ($gate['state'] === 'fail' && $count > 0 && $firstFinding !== null)
                        <button type="button" @class(['wf-gate-chip', 'is-'.$gate['state']]) wire:click="selectFile(@js($firstFinding))"
                                x-on:click="$nextTick(() => setTimeout(() => $root.querySelector('.wf-annot')?.scrollIntoView({ block: 'center' }), 400))">
                            ! {{ explode(' ', $gate['label'])[0] }} {{ $count }}
                        </button>
                    @else
                        <span @class(['wf-gate-chip', 'is-'.$gate['state']])>
                            @if ($gate['state'] === 'pass') ✓ @elseif ($gate['state'] === 'fail') ! @else <span class="wf-spin"></span> @endif
                            {{ explode(' ', $gate['label'])[0] }}
                        </span>
                    @endif
                @endforeach
            </span>
            <span class="wf-vsep" aria-hidden="true"></span>
        @endif
        <span class="wf-seg" role="group" aria-label="{{ __('workspaces.review.layout') }}">
            <button type="button" wire:click="setView('split')" aria-pressed="{{ $view === 'split' ? 'true' : 'false' }}">{{ __('workspaces.review.split') }}</button>
            <button type="button" wire:click="setView('unified')" aria-pressed="{{ $view === 'unified' ? 'true' : 'false' }}">{{ __('workspaces.review.unified') }}</button>
        </span>
        <button type="button" class="wf-icon-btn wf-icon-btn-sm wf-max-btn" x-on:click="window.dispatchEvent(new CustomEvent('review-maximize'))"
                :aria-label="maximized ? @js(__('workspaces.review.restore')) : @js(__('workspaces.review.maximize'))" :class="{ 'is-ink': maximized }">
            <x-wf.icon name="maximize" :size="15" :stroke="2" x-show="!maximized" />
            <x-wf.icon name="minimize" :size="15" :stroke="2" x-show="maximized" x-cloak />
        </button>
    </div>

    @if (! $running)
        <div class="wf-review-empty wf-muted">{{ __('workspaces.tabs.not_running') }}</div>
    @elseif ($error)
        <div class="wf-review-empty"><div class="wf-note-row is-error"><x-wf.icon name="alert" :size="15" :stroke="2" /> <span>{{ $error }}</span></div></div>
    @elseif ($files === [])
        <div class="wf-review-empty wf-muted">{{ __('workspaces.review.no_changes', ['branch' => $baseBranch]) }}</div>
    @else
        <div class="wf-review-body">
            <nav class="wf-tree" aria-label="{{ __('workspaces.focus.changed_files') }}">
                @foreach ($tree as $dir => $dirFiles)
                    <div class="wf-tree-dir mono">{{ $dir === '' ? '/' : $dir }}</div>
                    @foreach ($dirFiles as $f)
                        <button type="button" @class(['wf-tree-file', 'is-current' => $current['path'] === $f['path']])
                                wire:click="selectFile(@js($f['path']))" x-on:click="draft = null" @if ($current['path'] === $f['path']) aria-current="true" @endif title="{{ $f['path'] }}">
                            <span @class(['wf-file-status', 'mono', 'is-'.strtolower($f['status'])])>{{ $f['status'] }}</span>
                            <span class="wf-tree-name mono">{{ $f['name'] }}</span>
                            @if (($findingCounts[$f['path']] ?? 0) > 0)
                                <span class="wf-tree-badge is-warn" title="{{ trans_choice('workspaces.review.findings', $findingCounts[$f['path']], ['count' => $findingCounts[$f['path']]]) }}">{{ $findingCounts[$f['path']] }}</span>
                            @endif
                            <span class="wf-tree-badge is-info" x-show="changed.includes(@js($f['path']))" x-cloak>{{ __('workspaces.review.new') }}</span>
                            @if (in_array($f['path'], $seen, true))
                                <span class="wf-tree-seen" title="{{ __('workspaces.review.seen') }}"><x-wf.icon name="check" :size="13" :stroke="2.4" /></span>
                            @endif
                        </button>
                    @endforeach
                @endforeach
            </nav>

            <section class="wf-diff" aria-label="{{ $current['path'] }}">
                <div class="wf-diff-head">
                    <span class="mono wf-diff-path">{{ $current['path'] }}</span>
                    <span class="mono wf-diff-stat"><span class="wf-add">+{{ $current['additions'] }}</span> <span class="wf-del">−{{ $current['deletions'] }}</span></span>
                    <span class="wf-spacer"></span>
                    <label class="wf-seen-toggle">
                        <input type="checkbox" @checked(in_array($current['path'], $seen, true))
                               wire:change="toggleSeen(@js($current['path']), @js($current['hash']), $event.target.checked)">
                        {{ __('workspaces.review.seen') }}
                    </label>
                </div>

                <div class="wf-live" role="status" x-show="change" x-cloak>
                    <x-wf.icon name="refresh" :size="15" :stroke="2" />
                    <span class="wf-live-text" x-text="changeText(@js(__('workspaces.review.live_change', ['file' => '__F__', 'add' => '__A__', 'del' => '__D__'])))"></span>
                    <button type="button" class="wf-live-btn" x-on:click="showChange(@js($current['path']))">{{ __('workspaces.review.show_change') }}</button>
                </div>
                @if ($pintFlagged || $outsideFindings->isNotEmpty())
                    <div class="wf-findings">
                        @if ($pintFlagged)
                            <div class="wf-annot is-file">
                                <span class="wf-annot-gate">Pint</span>
                                <span class="wf-annot-msg">{{ __('workspaces.review.pint_flagged') }}</span>
                                @if ($canWrite)
                                    <button type="button" class="wf-annot-btn" wire:click="fixWithClaude(@js($current['path']), {{ $firstChangedLine }}, 'Pint', @js(__('workspaces.review.pint_flagged')))">{{ __('workspaces.review.fix') }}</button>
                                @endif
                            </div>
                        @endif
                        @if ($outsideFindings->isNotEmpty())
                            <div class="wf-findings-title wf-muted">{{ __('workspaces.review.findings_outside') }}</div>
                            @foreach ($outsideFindings as $finding)
                                <div class="wf-annot">
                                    <span class="wf-annot-gate">PHPStan</span>
                                    <span class="wf-annot-msg"><span class="mono">:{{ $finding['line'] }}</span> {{ $finding['message'] }}</span>
                                    @if ($canWrite)
                                        <button type="button" class="wf-annot-btn" wire:click="fixWithClaude(@js($current['path']), {{ $finding['line'] }}, 'PHPStan', @js($finding['message']))">{{ __('workspaces.review.fix') }}</button>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif

                <div @class(['wf-diff-body', 'mono', 'is-split' => $view === 'split', 'is-unified' => $view === 'unified']) wire:key="diff-{{ md5($current['path']) }}-{{ $view }}">
                    @foreach ($current['hunks'] as $hunk)
                        @php $gapKey = $current['path'].'#'.$hunk['index']; @endphp
                        @if ($hunk['gap'] !== null)
                            @if (isset($expanded[$gapKey]))
                                @foreach ($expanded[$gapKey] as $line)
                                    @include('livewire.workspace.partials.diff-row', ['row' => ['left' => $line, 'right' => $line], 'line' => $line])
                                @endforeach
                            @else
                                <button type="button" class="wf-diff-gap" wire:click="expand(@js($current['path']), {{ $hunk['index'] }})">
                                    ⋯ {{ trans_choice('workspaces.review.unchanged', $hunk['gap']['to'] - $hunk['gap']['from'] + 1, ['count' => $hunk['gap']['to'] - $hunk['gap']['from'] + 1]) }}
                                </button>
                            @endif
                        @endif
                        <div class="wf-diff-hunk">{{ $hunk['header'] }}</div>
                        @if ($view === 'split')
                            @foreach ($hunk['split'] as $row)
                                @include('livewire.workspace.partials.diff-row', ['row' => $row, 'line' => null])
                            @endforeach
                        @else
                            @foreach ($hunk['lines'] as $line)
                                @include('livewire.workspace.partials.diff-row', ['row' => null, 'line' => $line])
                            @endforeach
                        @endif
                    @endforeach
                </div>

                <div class="wf-diff-foot">
                    <span class="wf-muted">{{ __('workspaces.review.tip') }}</span>
                    <span class="wf-spacer"></span>
                    <button type="button" class="wf-btn wf-btn-sm" @disabled($previous === null) @if ($previous) wire:click="selectFile(@js($previous))" @endif>
                        <x-wf.icon name="arrow-left" :size="14" :stroke="2" /> {{ __('workspaces.review.previous') }}
                    </button>
                    <button type="button" class="wf-btn wf-btn-sm" @disabled($next === null) @if ($next) wire:click="selectFile(@js($next))" @endif>
                        {{ __('workspaces.review.next') }} <x-wf.icon name="arrow-right" :size="14" :stroke="2" />
                    </button>
                </div>
            </section>
        </div>
    @endif
@endif
</div>
