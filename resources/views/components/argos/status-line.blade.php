@props([
    'state' => 'draft',   // running | queued | waiting | paused | failed | done | draft
    'title' => '',
    'hint' => null,        // secondary line (e.g. "respond below" / resume hint)
    'startedAt' => null,   // epoch seconds — renders a live mm:ss timer (running only)
    'error' => null,       // error text to surface (failed only)
    'logsUrl' => null,     // optional link (e.g. logs / quality gates)
    'logsLabel' => null,
    'flush' => false,      // drop the standalone border/radius when nested in a card
])
{{--
    The coloured "what is the system doing right now" row. Used standalone or as
    the lower band of <x-argos.status-banner> beneath the phase stepper. Driven
    by App\Support\Workflow\TaskStage::bannerState().
--}}
@php
    $map = [
        'running' => ['cls' => 'callout-info', 'icon' => null],
        'queued'  => ['cls' => 'callout-info', 'icon' => 'spin'],
        'waiting' => ['cls' => 'callout-warn', 'icon' => 'heroicon-o-hand-raised'],
        'paused'  => ['cls' => 'callout-warn', 'icon' => 'heroicon-o-pause-circle'],
        'failed'  => ['cls' => 'callout-danger', 'icon' => 'heroicon-o-exclamation-triangle'],
        'done'    => ['cls' => 'callout-ok', 'icon' => 'heroicon-o-check-circle'],
        'aborted' => ['cls' => 'callout-warn', 'icon' => 'heroicon-o-no-symbol'],
        'draft'   => ['cls' => 'callout-info', 'icon' => 'heroicon-o-document-text'],
    ];
    $m = $map[$state] ?? $map['draft'];
@endphp
<div {{ $attributes->merge(['class' => 'callout status-banner '.$m['cls'].($flush ? ' sb-flush' : '')]) }}>
    @if ($state === 'running')
        <span class="sb-dot"></span>
    @elseif ($m['icon'] === 'spin')
        <svg class="sb-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
    @else
        @svg($m['icon'])
    @endif

    <div class="sb-main">
        <span class="sb-title">{{ $title }}</span>
        @if ($hint)
            <span class="sb-hint">{{ $hint }}</span>
        @endif
        @if ($error)
            <pre class="sb-err">{{ $error }}</pre>
        @endif
        @if ($logsUrl)
            <a href="{{ $logsUrl }}" class="link-btn" style="margin-top:6px;align-self:flex-start;">
                @svg('heroicon-o-command-line') {{ $logsLabel ?? __('tasks.view.banner.view_logs') }}
            </a>
        @endif
    </div>

    @if ($state === 'running' && $startedAt !== null)
        <span class="sb-timer"
              x-data="{ sec: Math.max(0, Math.floor(Date.now()/1000) - {{ $startedAt }}) }"
              x-init="setInterval(() => sec++, 1000)"
              x-text="Math.floor(sec/60) + ':' + String(sec % 60).padStart(2, '0')"></span>
    @endif
</div>
