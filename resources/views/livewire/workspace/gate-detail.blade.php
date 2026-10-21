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
        <div class="wf-gd-layer" x-show="open" x-cloak x-transition.opacity.duration.150ms>
            <div class="wf-gd-backdrop" x-on:click="close()" aria-hidden="true"></div>
            <section class="wf-gd" role="dialog" aria-modal="true" aria-labelledby="wf-gd-title" x-trap.noscroll="open">
                <header class="wf-gd-head">
                    <div class="wf-gd-heading">
                        <span class="wf-gd-kicker">{{ __('workspaces.gate_detail.title') }}</span>
                        <h2 class="wf-gd-title" id="wf-gd-title">
                            @if ($detail !== null)
                                {{ $detail['row']['label'] }}
                                <span @class(['wf-gd-badge', 'is-'.$detail['row']['state']])>{{ __('workspaces.focus.gate_state.'.$detail['row']['state']) }}</span>
                            @endif
                        </h2>
                    </div>
                    <button type="button" class="wf-icon-btn wf-gd-close" x-ref="close" x-on:click="close()" title="{{ __('workspaces.gate_detail.close') }}" aria-label="{{ __('workspaces.gate_detail.close') }}">
                        <x-wf.icon name="x" :size="18" />
                    </button>
                </header>

                <div class="wf-gd-loading" wire:loading.flex wire:target="show"><span class="wf-spin"></span></div>

                <div class="wf-gd-body" wire:loading.remove wire:target="show">
                    @if ($detail !== null)
                        @php($row = $detail['row'])
                        <dl class="wf-gd-meta">
                            <div><dt>{{ __('workspaces.gate_detail.result') }}</dt><dd>{{ __('workspaces.focus.gate_state.'.$row['state']) }}@if (count($detail['results']) > 1)<span class="wf-muted"> · {{ implode(', ', array_map(fn (string $k, string $r): string => $k.' '.__('workspaces.focus.gate_state.'.$r), array_keys($detail['results']), $detail['results'])) }}</span>@endif</dd></div>
                            @if ($detail['duration'] !== null)
                                <div><dt>{{ __('workspaces.gate_detail.duration') }}</dt><dd>{{ $detail['duration'] }}</dd></div>
                            @endif
                            @if ($detail['ranAt'] !== null)
                                <div><dt>{{ __('workspaces.gate_detail.ran') }}</dt><dd title="{{ $detail['ranAt']->isoFormat('LLL') }}">{{ $detail['ranAt']->diffForHumans() }}@if ($detail['run']?->triggeredBy) · {{ $detail['run']->triggeredBy->name }}@endif</dd></div>
                            @endif
                            @if ($detail['baseline'] !== null)
                                <div class="wf-gd-meta-wide"><dt>{{ __('workspaces.gate_detail.baseline') }}</dt><dd>{{ $detail['baseline'] }}</dd></div>
                            @endif
                        </dl>

                        @if ($row['state'] === 'open')
                            <p class="wf-note wf-muted">{{ __('workspaces.gate_detail.not_run') }}</p>
                        @elseif ($row['state'] === 'running')
                            <p class="wf-note wf-muted"><span class="wf-spin"></span> {{ __('workspaces.gate_detail.running') }}</p>
                        @endif

                        @if ($detail['canFix'])
                            <div class="wf-gd-actions">
                                @foreach ($row['failed'] as $failedGate)
                                    <button type="button" class="wf-btn wf-btn-primary" wire:click="sendGateFix(@js($failedGate))" wire:loading.attr="disabled" wire:target="sendGateFix">
                                        <x-wf.icon name="zap" :size="16" /> {{ __('workspaces.gates.send_fix') }}@if (count($row['failed']) > 1) ({{ $failedGate }})@endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($detail['files'] !== [])
                            <section class="wf-gd-section">
                                <h3 class="wf-section-title">{{ __('workspaces.gate_detail.files') }} · {{ count($detail['files']) }}</h3>
                                <ul class="wf-gd-files mono">
                                    @foreach ($detail['files'] as $file)
                                        <li>
                                            <button type="button" class="wf-gd-file" title="{{ $file['path'] }}" x-on:click="openFile(@js($file['path']))">
                                                <span class="wf-gd-file-name">{{ basename($file['path']) }}@if (str_contains($file['path'], '/'))<span class="wf-file-dir"> {{ dirname($file['path']) }}</span>@endif</span>
                                                <span class="wf-gd-count">{{ trans_choice('workspaces.gate_detail.findings', $file['count'], ['count' => $file['count']]) }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        @if (! in_array($row['state'], ['open', 'running'], true))
                            <section class="wf-gd-section wf-gd-logsec">
                                <div class="wf-gd-loghead">
                                    <h3 class="wf-section-title">{{ __('workspaces.gate_detail.log') }}</h3>
                                    @if ($detail['log'] !== '')
                                        <label class="wf-gd-search">
                                            <x-wf.icon name="search" :size="15" />
                                            <input type="search" x-model="q" x-on:input.debounce.150ms="search()" placeholder="{{ __('workspaces.gate_detail.search') }}" aria-label="{{ __('workspaces.gate_detail.search') }}">
                                        </label>
                                    @endif
                                </div>
                                @if (! $detail['complete'])
                                    <p class="wf-note wf-muted">{{ __('workspaces.gate_detail.log_tail') }}</p>
                                @elseif ($detail['truncated'])
                                    <p class="wf-note wf-muted">{{ __('workspaces.gate_detail.log_truncated') }}</p>
                                @endif
                                @if ($detail['log'] === '')
                                    <p class="wf-note wf-muted">{{ __('workspaces.gate_detail.empty_log') }}</p>
                                @else
                                    <p class="wf-note wf-muted" x-show="searching" x-cloak aria-live="polite"
                                       x-text="count === 0 ? @js(trans_choice('workspaces.gate_detail.matches', 0)) : (count === 1 ? @js(trans_choice('workspaces.gate_detail.matches', 1)) : @js(trans_choice('workspaces.gate_detail.matches', 2, ['count' => '#'])).replace('#', count)) + (capped ? ' · ' + @js(__('workspaces.gate_detail.more_matches', ['count' => 2000])) : '')"></p>
                                    <pre class="wf-gd-log mono" x-ref="raw" x-show="!searching">{{ $detail['log'] }}</pre>
                                    <pre class="wf-gd-log mono is-filtered" x-show="searching" x-cloak x-html="html"></pre>
                                @endif
                            </section>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </template>
</div>
