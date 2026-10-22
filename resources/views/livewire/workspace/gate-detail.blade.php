@php
    /** @var array<string, mixed>|null $detail */
@endphp
{{-- Gate detail slide-over. Teleported to <body> so it escapes the context
     drawer (transformed on narrow screens). Opened by `gate-detail-open`. --}}
<div x-data="argosGateDetail()"
     x-on:gate-detail-open.window="show($event.detail)"
     x-on:gate-detail-closed.window="close()"
     x-on:keydown.escape.window.capture="if (open) { $event.preventDefault(); close() }">
    <template x-teleport="body">
        <div class="ag-gd-layer" x-show="open" x-cloak x-transition.opacity.duration.150ms>
            <div class="ag-gd-backdrop" x-on:click="close()" aria-hidden="true"></div>
            <section class="ag-gd" role="dialog" aria-modal="true" aria-labelledby="ag-gd-title" x-trap.noscroll="open">
                <header class="ag-gd-head">
                    <div class="ag-gd-heading">
                        <span class="ag-gd-kicker">{{ __('workspaces.gate_detail.title') }}</span>
                        <h2 class="ag-gd-title" id="ag-gd-title">
                            @if ($detail !== null)
                                {{ $detail['row']['label'] }}
                                <span @class(['ag-gd-badge', 'is-'.$detail['row']['state']])>{{ __('workspaces.focus.gate_state.'.$detail['row']['state']) }}</span>
                            @endif
                        </h2>
                    </div>
                    <button type="button" class="ag-icon-btn ag-gd-close" x-ref="close" x-on:click="close()" title="{{ __('workspaces.gate_detail.close') }}" aria-label="{{ __('workspaces.gate_detail.close') }}">
                        <x-ag.icon name="x" :size="18" />
                    </button>
                </header>

                <div class="ag-gd-loading" wire:loading.flex wire:target="show"><span class="ag-spin"></span></div>

                <div class="ag-gd-body" wire:loading.remove wire:target="show">
                    @if ($detail !== null)
                        @php($row = $detail['row'])
                        <dl class="ag-gd-meta">
                            <div><dt>{{ __('workspaces.gate_detail.result') }}</dt><dd>{{ __('workspaces.focus.gate_state.'.$row['state']) }}@if (count($detail['results']) > 1)<span class="ag-muted"> · {{ implode(', ', array_map(fn (string $k, string $r): string => $k.' '.__('workspaces.focus.gate_state.'.$r), array_keys($detail['results']), $detail['results'])) }}</span>@endif</dd></div>
                            @if ($detail['duration'] !== null)
                                <div><dt>{{ __('workspaces.gate_detail.duration') }}</dt><dd>{{ $detail['duration'] }}</dd></div>
                            @endif
                            @if ($detail['ranAt'] !== null)
                                <div><dt>{{ __('workspaces.gate_detail.ran') }}</dt><dd title="{{ $detail['ranAt']->isoFormat('LLL') }}">{{ $detail['ranAt']->diffForHumans() }}@if ($detail['run']?->triggeredBy) · {{ $detail['run']->triggeredBy->name }}@endif</dd></div>
                            @endif
                            @if ($detail['baseline'] !== null)
                                <div class="ag-gd-meta-wide"><dt>{{ __('workspaces.gate_detail.baseline') }}</dt><dd>{{ $detail['baseline'] }}</dd></div>
                            @endif
                        </dl>

                        @if ($row['state'] === 'open')
                            <p class="ag-note ag-muted">{{ __('workspaces.gate_detail.not_run') }}</p>
                        @elseif ($row['state'] === 'running')
                            <p class="ag-note ag-muted"><span class="ag-spin"></span> {{ __('workspaces.gate_detail.running') }}</p>
                        @endif

                        @if ($detail['canFix'] || $detail['canRerun'])
                            <div class="ag-gd-actions">
                                @if ($detail['canRerun'])
                                    <button type="button" class="ag-btn" wire:click="rerunGate(@js($row['key']))" wire:loading.attr="disabled" wire:target="rerunGate">
                                        <x-ag.icon name="refresh" :size="15" :stroke="2" /> {{ __('workspaces.gates.rerun_one') }}
                                    </button>
                                @endif
                                @foreach ($detail['canFix'] ? $row['failed'] : [] as $failedGate)
                                    <button type="button" class="ag-btn ag-btn-primary" wire:click="sendGateFix(@js($failedGate))" wire:loading.attr="disabled" wire:target="sendGateFix">
                                        <x-ag.icon name="zap" :size="16" /> {{ __('workspaces.gates.send_fix') }}@if (count($row['failed']) > 1) ({{ $failedGate }})@endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($detail['files'] !== [])
                            <section class="ag-gd-section">
                                <h3 class="ag-section-title">{{ __('workspaces.gate_detail.files') }} · {{ count($detail['files']) }}</h3>
                                <ul class="ag-gd-files mono">
                                    @foreach ($detail['files'] as $file)
                                        <li>
                                            <button type="button" class="ag-gd-file" title="{{ $file['path'] }}" x-on:click="openFile(@js($file['path']))">
                                                <span class="ag-gd-file-name">{{ basename($file['path']) }}@if (str_contains($file['path'], '/'))<span class="ag-file-dir"> {{ dirname($file['path']) }}</span>@endif</span>
                                                <span class="ag-gd-count">{{ trans_choice('workspaces.gate_detail.findings', $file['count'], ['count' => $file['count']]) }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        @if (! in_array($row['state'], ['open', 'running'], true))
                            <section class="ag-gd-section ag-gd-logsec">
                                <div class="ag-gd-loghead">
                                    <h3 class="ag-section-title">{{ __('workspaces.gate_detail.log') }}</h3>
                                    @if ($detail['log'] !== '')
                                        <label class="ag-gd-search">
                                            <x-ag.icon name="search" :size="15" />
                                            <input type="search" x-model="q" x-on:input.debounce.150ms="search()" placeholder="{{ __('workspaces.gate_detail.search') }}" aria-label="{{ __('workspaces.gate_detail.search') }}">
                                        </label>
                                    @endif
                                </div>
                                @if (! $detail['complete'])
                                    <p class="ag-note ag-muted">{{ __('workspaces.gate_detail.log_tail') }}</p>
                                @elseif ($detail['truncated'])
                                    <p class="ag-note ag-muted">{{ __('workspaces.gate_detail.log_truncated') }}</p>
                                @endif
                                @if ($detail['log'] === '')
                                    <p class="ag-note ag-muted">{{ __('workspaces.gate_detail.empty_log') }}</p>
                                @else
                                    <p class="ag-note ag-muted" x-show="searching" x-cloak aria-live="polite"
                                       x-text="count === 0 ? @js(trans_choice('workspaces.gate_detail.matches', 0)) : (count === 1 ? @js(trans_choice('workspaces.gate_detail.matches', 1)) : @js(trans_choice('workspaces.gate_detail.matches', 2, ['count' => '#'])).replace('#', count)) + (capped ? ' · ' + @js(__('workspaces.gate_detail.more_matches', ['count' => 2000])) : '')"></p>
                                    <pre class="ag-gd-log mono" x-ref="raw" x-show="!searching">{{ $detail['log'] }}</pre>
                                    <pre class="ag-gd-log mono is-filtered" x-show="searching" x-cloak x-html="html"></pre>
                                @endif
                            </section>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </template>
</div>
