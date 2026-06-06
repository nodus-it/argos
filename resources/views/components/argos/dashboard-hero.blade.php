@props([
    'title',
    'sub',
    'tickerLines' => [],  // array of ['time' => '', 'text' => '', 'class' => 't-ok|t-info|t-accent|t-warn|t-err']
    'idleText' => null,
    'running' => 0,
    'waiting' => 0,
    'failed' => 0,
])
{{--
    Full dashboard hero: Eye + rings + glow (left) + terminal + status bar (right).
    Used in DashboardHeroWidget.
--}}
<div class="dh-hero cr-scope">
    <x-argos.cr-bg />

    {{-- Eye + rings stage --}}
    <div class="dh-eye-stage" aria-hidden="true">
        <div class="dh-ring r1"></div>
        <div class="dh-ring r2"></div>
        <div class="dh-ring r3"></div>
        <div class="dh-eye-glow"></div>
        <svg class="eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
            <circle cx="12" cy="12" r="3"/>
            <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/>
        </svg>
    </div>

    {{-- Headline --}}
    <div class="dh-txt">
        <h1>{!! $title !!}</h1>
        <p class="sub">{{ $sub }}</p>
    </div>

    {{-- Terminal + status bar (right column) --}}
    <div class="dh-right">
        <div class="dh-term">
            <div class="dh-term-head">
                <i class="d1"></i><i class="d2"></i><i class="d3"></i>
                <span class="tt">argos.log</span>
                <span class="live">
                    <span class="dot"></span>
                    LIVE
                </span>
            </div>
            <div class="dh-term-body">
                @if ($tickerLines)
                    @foreach ($tickerLines as $line)
                        <div class="dh-term-line">
                            <span class="tt">{{ $line['time'] ?? '' }}</span>
                            <span class="{{ $line['class'] ?? 't-info' }}">{{ $line['text'] }}</span>
                        </div>
                    @endforeach
                @else
                    <div class="dh-term-line">
                        <span class="t-info">{{ $idleText ?? __('dashboard.hero.idle') }}</span>
                    </div>
                @endif
            </div>
            <div class="dh-status">
                <div class="dh-status-item d-run">
                    <span class="num">{{ $running }}</span>
                    <span>{{ __('dashboard.hero.status.running') }}</span>
                </div>
                <div class="dh-status-item d-wait">
                    <span class="num">{{ max(0, $waiting - $failed) }}</span>
                    <span>{{ __('dashboard.hero.status.waiting') }}</span>
                </div>
                <div class="dh-status-item d-err">
                    <span class="num">{{ $failed }}</span>
                    <span>{{ __('dashboard.hero.status.failed') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
