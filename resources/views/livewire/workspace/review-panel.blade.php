{{-- Review mode: toolbar, file tree and the diff of one file. Opens and
     closes with the page's review state (resources/js/workspace/focus.js). --}}
<div class="ag-review" x-data="argosReview(@js(__('workspaces.review.comment_on', ['line' => '__L__'])))"
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
    <div class="ag-review-bar">
        <span class="ag-review-title">{{ __('workspaces.review.changes') }}</span>
        <span class="mono ag-review-stat"><span class="ag-add">+{{ $additions }}</span> <span class="ag-del">−{{ $deletions }}</span></span>
        <span class="ag-muted ag-review-seen">{{ __('workspaces.review.seen_progress', ['seen' => $seenCount, 'total' => count($files)]) }}</span>
        <span class="ag-spacer"></span>
        @if ($gates !== [])
            <span class="ag-gate-chips" role="group" aria-label="{{ __('workspaces.focus.gates') }}">
                @foreach ($gates as $gate)
                    @php $count = $gateCounts[$gate['key']] ?? 0; @endphp
                    @if ($gate['state'] === 'fail' && $count > 0 && $firstFinding !== null)
                        <button type="button" @class(['ag-gate-chip', 'is-'.$gate['state']]) wire:click="selectFile(@js($firstFinding))"
                                x-on:click="$nextTick(() => setTimeout(() => $root.querySelector('.ag-annot')?.scrollIntoView({ block: 'center' }), 400))">
                            ! {{ explode(' ', $gate['label'])[0] }} {{ $count }}
                        </button>
                    @else
                        <button type="button" @class(['ag-gate-chip', 'is-'.$gate['state']]) aria-haspopup="dialog" title="{{ __('workspaces.gate_detail.title') }}: {{ $gate['label'] }}"
                                x-on:click="$dispatch('gate-detail-open', { taskId: @js($taskId), gate: @js($gate['key']) })">
                            @if ($gate['state'] === 'pass') ✓ @elseif ($gate['state'] === 'fail') ! @else <span class="ag-spin"></span> @endif
                            {{ explode(' ', $gate['label'])[0] }}
                        </button>
                    @endif
                @endforeach
            </span>
            <span class="ag-vsep" aria-hidden="true"></span>
        @endif
        <span class="ag-seg" role="group" aria-label="{{ __('workspaces.review.layout') }}">
            <button type="button" wire:click="setView('split')" aria-pressed="{{ $view === 'split' ? 'true' : 'false' }}">{{ __('workspaces.review.split') }}</button>
            <button type="button" wire:click="setView('unified')" aria-pressed="{{ $view === 'unified' ? 'true' : 'false' }}">{{ __('workspaces.review.unified') }}</button>
        </span>
        <button type="button" class="ag-icon-btn ag-icon-btn-sm ag-max-btn" x-on:click="window.dispatchEvent(new CustomEvent('review-maximize'))"
                :aria-label="maximized ? @js(__('workspaces.review.restore')) : @js(__('workspaces.review.maximize'))" :class="{ 'is-ink': maximized }">
            <x-ag.icon name="maximize" :size="15" :stroke="2" x-show="!maximized" />
            <x-ag.icon name="minimize" :size="15" :stroke="2" x-show="maximized" x-cloak />
        </button>
    </div>

    @if (! $running)
        <div class="ag-review-empty ag-muted">{{ __('workspaces.tabs.not_running') }}</div>
    @elseif ($error)
        <div class="ag-review-empty"><div class="ag-note-row is-error"><x-ag.icon name="alert" :size="15" :stroke="2" /> <span>{{ $error }}</span></div></div>
    @elseif ($files === [])
        <div class="ag-review-empty ag-muted">{{ __('workspaces.review.no_changes', ['branch' => $baseBranch]) }}</div>
    @else
        <div class="ag-review-body">
            <nav class="ag-tree" aria-label="{{ __('workspaces.focus.changed_files') }}">
                @foreach ($tree as $dir => $dirFiles)
                    <div class="ag-tree-dir mono">{{ $dir === '' ? '/' : $dir }}</div>
                    @foreach ($dirFiles as $f)
                        <button type="button" @class(['ag-tree-file', 'is-current' => $current['path'] === $f['path']])
                                wire:click="selectFile(@js($f['path']))" x-on:click="draft = null" @if ($current['path'] === $f['path']) aria-current="true" @endif title="{{ $f['path'] }}">
                            <span @class(['ag-file-status', 'mono', 'is-'.strtolower($f['status'])])>{{ $f['status'] }}</span>
                            <span class="ag-tree-name mono">{{ $f['name'] }}</span>
                            @if (($findingCounts[$f['path']] ?? 0) > 0)
                                <span class="ag-tree-badge is-warn" title="{{ trans_choice('workspaces.review.findings', $findingCounts[$f['path']], ['count' => $findingCounts[$f['path']]]) }}">{{ $findingCounts[$f['path']] }}</span>
                            @endif
                            <span class="ag-tree-badge is-info" x-show="changed.includes(@js($f['path']))" x-cloak>{{ __('workspaces.review.new') }}</span>
                            @if (in_array($f['path'], $seen, true))
                                <span class="ag-tree-seen" title="{{ __('workspaces.review.seen') }}"><x-ag.icon name="check" :size="13" :stroke="2.4" /></span>
                            @endif
                        </button>
                    @endforeach
                @endforeach
                @if ($generatedFiles !== [])
                    <details class="ag-tree-generated" @if ($current['generated'] ?? false) open @endif>
                        <summary class="ag-tree-dir">{{ trans_choice('workspaces.review.generated_group', count($generatedFiles), ['count' => count($generatedFiles)]) }}</summary>
                        @foreach ($generatedFiles as $f)
                            <button type="button" @class(['ag-tree-file', 'is-current' => $current['path'] === $f['path']]) wire:click="selectFile(@js($f['path']))" x-on:click="draft = null" title="{{ $f['path'] }}">
                                <span @class(['ag-file-status', 'mono', 'is-'.strtolower($f['status'])])>{{ $f['status'] }}</span>
                                <span class="ag-tree-name mono">{{ $f['name'] }}</span>
                                <span class="ag-muted mono ag-tree-count">+{{ $f['additions'] }}</span>
                            </button>
                        @endforeach
                    </details>
                @endif
            </nav>

            <section class="ag-diff" aria-label="{{ $current['path'] }}">
                <div class="ag-diff-head">
                    <span class="mono ag-diff-path">{{ $current['path'] }}</span>
                    <span class="mono ag-diff-stat"><span class="ag-add">+{{ $current['additions'] }}</span> <span class="ag-del">−{{ $current['deletions'] }}</span></span>
                    <span class="ag-spacer"></span>
                    @if ($isMarkdown)
                        <div class="ag-seg ag-md-toggle" role="group" aria-label="{{ __('workspaces.review.markdown_view') }}">
                            <button type="button" aria-pressed="{{ $rendered ? 'true' : 'false' }}" wire:click="setRendered(true)">{{ __('workspaces.review.rendered') }}</button>
                            <button type="button" aria-pressed="{{ $rendered ? 'false' : 'true' }}" wire:click="setRendered(false)">{{ __('workspaces.review.diff') }}</button>
                        </div>
                    @endif
                    <label class="ag-seen-toggle">
                        <input type="checkbox" @checked(in_array($current['path'], $seen, true))
                               wire:change="toggleSeen(@js($current['path']), @js($current['hash']), $event.target.checked)">
                        {{ __('workspaces.review.seen') }}
                    </label>
                </div>

                <div class="ag-live" role="status" x-show="change" x-cloak>
                    <x-ag.icon name="refresh" :size="15" :stroke="2" />
                    <span class="ag-live-text" x-text="changeText(@js(__('workspaces.review.live_change', ['file' => '__F__', 'add' => '__A__', 'del' => '__D__'])))"></span>
                    <button type="button" class="ag-live-btn" x-on:click="showChange(@js($current['path']))">{{ __('workspaces.review.show_change') }}</button>
                </div>
                @if ($prCommentsOutside->isNotEmpty())
                    <div class="ag-findings" data-testid="pr-comments-outside">
                        <div class="ag-findings-title ag-muted">{{ __('workspaces.review.pr_comments_outside') }}</div>
                        @foreach ($prCommentsOutside as $prComment)
                            @include('livewire.workspace.partials.pr-comment', ['prComment' => $prComment, 'pos' => 'unified'])
                        @endforeach
                    </div>
                @endif
                @if ($pintFlagged || $outsideFindings->isNotEmpty())
                    <div class="ag-findings">
                        @if ($pintFlagged)
                            <div class="ag-annot is-file">
                                <span class="ag-annot-gate">Pint</span>
                                <span class="ag-annot-msg">{{ __('workspaces.review.pint_flagged') }}</span>
                                @if ($canWrite)
                                    <button type="button" class="ag-annot-btn" wire:click="fixWithClaude(@js($current['path']), {{ $firstChangedLine }}, 'Pint', @js(__('workspaces.review.pint_flagged')))">{{ __('workspaces.review.fix') }}</button>
                                @endif
                            </div>
                        @endif
                        @if ($outsideFindings->isNotEmpty())
                            <div class="ag-findings-title ag-muted">{{ __('workspaces.review.findings_outside') }}</div>
                            @foreach ($outsideFindings as $finding)
                                <div class="ag-annot">
                                    <span class="ag-annot-gate">PHPStan</span>
                                    <span class="ag-annot-msg"><span class="mono">:{{ $finding['line'] }}</span> {{ $finding['message'] }}</span>
                                    @if ($canWrite)
                                        <button type="button" class="ag-annot-btn" wire:click="fixWithClaude(@js($current['path']), {{ $finding['line'] }}, 'PHPStan', @js($finding['message']))">{{ __('workspaces.review.fix') }}</button>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif

                @if ($markdown !== null)
                    <div class="ag-md-preview">
                        <p class="ag-muted ag-md-preview-hint">{{ __('workspaces.review.rendered_hint') }}</p>
                        <article class="ag-md ws-md prose" wire:key="md-{{ md5($current['path']) }}">{{ $markdown }}</article>
                    </div>
                @else
                <div @class(['ag-diff-body', 'mono', 'is-split' => $view === 'split', 'is-unified' => $view === 'unified']) wire:key="diff-{{ md5($current['path']) }}-{{ $view }}">
                    @if ($current['skipped'] === 'generated')
                        <div class="ag-diff-skipped">
                            <p>{{ __('workspaces.review.generated_hidden') }}</p>
                            <button type="button" class="ag-btn ag-btn-sm" wire:click="loadGeneratedDiff(@js($current['path']))">{{ __('workspaces.review.load_anyway') }}</button>
                        </div>
                    @elseif ($current['skipped'] === 'binary')
                        <div class="ag-diff-skipped"><p>{{ __('workspaces.review.binary') }}</p></div>
                    @endif
                    @foreach ($current['hunks'] as $hunk)
                        @php $gapKey = $current['path'].'#'.$hunk['index']; @endphp
                        @if ($hunk['gap'] !== null)
                            @if (isset($expanded[$gapKey]))
                                @foreach ($expanded[$gapKey] as $line)
                                    @include('livewire.workspace.partials.diff-row', ['row' => ['left' => $line, 'right' => $line], 'line' => $line])
                                @endforeach
                            @else
                                <button type="button" class="ag-diff-gap" wire:click="expand(@js($current['path']), {{ $hunk['index'] }})">
                                    ⋯ {{ trans_choice('workspaces.review.unchanged', $hunk['gap']['to'] - $hunk['gap']['from'] + 1, ['count' => $hunk['gap']['to'] - $hunk['gap']['from'] + 1]) }}
                                </button>
                            @endif
                        @endif
                        <div class="ag-diff-hunk">{{ $hunk['header'] }}</div>
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
                    @if ($current['hidden'] > 0)
                        <button type="button" class="ag-diff-gap ag-diff-more" wire:click="showMoreLines(@js($current['path']))">
                            {{ trans_choice('workspaces.review.more_lines', $current['hidden'], ['count' => $current['hidden']]) }}
                        </button>
                    @endif
                </div>
                @endif

                <div class="ag-diff-foot">
                    <span class="ag-muted">{{ __('workspaces.review.tip') }}</span>
                    <span class="ag-spacer"></span>
                    <button type="button" class="ag-btn ag-btn-sm" @disabled($previous === null) @if ($previous) wire:click="selectFile(@js($previous))" @endif>
                        <x-ag.icon name="arrow-left" :size="14" :stroke="2" /> {{ __('workspaces.review.previous') }}
                    </button>
                    <button type="button" class="ag-btn ag-btn-sm" @disabled($next === null) @if ($next) wire:click="selectFile(@js($next))" @endif>
                        {{ __('workspaces.review.next') }} <x-ag.icon name="arrow-right" :size="14" :stroke="2" />
                    </button>
                </div>
            </section>
        </div>
    @endif
@endif
</div>
