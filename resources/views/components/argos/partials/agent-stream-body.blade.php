{{-- Renders the agent-stream event list. Expects $events, $icons, $live. --}}
@forelse ($events as $event)
    @php
        $kind = $event['kind'] ?? 'text';
        $moreLabel = __('tasks.view.stream.show_more');
        $lessLabel = __('tasks.view.stream.show_less');
    @endphp

    @if ($kind === 'argos')
        <div class="as-item as-argos lv-{{ $event['level'] ?? 'info' }}">
            <span class="as-ico">{{ $icons['argos'] }}</span>
            <span class="as-main"><span class="as-tag">argos</span><span class="as-text">{{ $event['text'] }}</span></span>
        </div>

    @elseif ($kind === 'thinking')
        <div class="as-item as-think" x-data="{ open: false }">
            <span class="as-ico">{{ $icons['thinking'] }}</span>
            <span class="as-main">
                <span class="as-tag">{{ __('tasks.view.stream.thinking') }}</span>
                <span class="as-text">{{ $event['text'] }}</span>
                @if ($event['truncated'] ?? false)
                    <span class="as-toggle" x-on:click="open = !open" x-text="open ? @js($lessLabel) : @js($moreLabel)"></span>
                    <div class="as-more" x-show="open" x-cloak>{{ $event['full'] }}</div>
                @endif
            </span>
        </div>

    @elseif ($kind === 'text')
        <div class="as-item as-say">
            <span class="as-ico">{{ $icons['text'] }}</span>
            <span class="as-main as-text">{{ $event['text'] }}</span>
        </div>

    @elseif ($kind === 'tool_use')
        <div class="as-item as-tool" x-data="{ open: false }">
            <span class="as-ico">{{ $icons['tool_use'] }}</span>
            <span class="as-main">
                <span class="name">{{ $event['tool'] }}</span>
                @if (($event['summary'] ?? '') !== '')
                    <span class="summary">{{ $event['summary'] }}</span>
                @endif
                @if ($event['input_full'] ?? null)
                    <span class="as-toggle" x-on:click="open = !open" x-text="open ? @js($lessLabel) : @js(__('tasks.view.stream.show_input'))"></span>
                    <div class="as-more" x-show="open" x-cloak>{{ $event['input_full'] }}</div>
                @endif

                @if ($event['result'] ?? null)
                    @php $res = $event['result']; @endphp
                    <div class="as-tres {{ ($res['is_error'] ?? false) ? 'err' : '' }}" x-data="{ ropen: false }">
                        <span class="as-ico">{{ $icons['tool_result'] }}</span>
                        <span class="as-text">
                            {{ $res['text'] }}
                            @if ($res['truncated'] ?? false)
                                <span class="as-toggle" x-on:click="ropen = !ropen" x-text="ropen ? @js($lessLabel) : @js($moreLabel)"></span>
                                <div class="as-more" x-show="ropen" x-cloak>{{ $res['full'] }}</div>
                            @endif
                        </span>
                    </div>
                @endif
            </span>
        </div>

    @elseif ($kind === 'tool_result')
        <div class="as-item as-tres {{ ($event['is_error'] ?? false) ? 'err' : '' }}" x-data="{ open: false }">
            <span class="as-ico">{{ $icons['tool_result'] }}</span>
            <span class="as-main as-text">
                {{ $event['text'] }}
                @if ($event['truncated'] ?? false)
                    <span class="as-toggle" x-on:click="open = !open" x-text="open ? @js($lessLabel) : @js($moreLabel)"></span>
                    <div class="as-more" x-show="open" x-cloak>{{ $event['full'] }}</div>
                @endif
            </span>
        </div>

    @elseif ($kind === 'result')
        @php
            $resultText = __('tasks.view.stream.completed');
            if (($event['cost'] ?? 0) > 0) {
                $resultText .= ' · $'.number_format((float) $event['cost'], 4);
            }
            if (($event['tokens'] ?? 0) > 0) {
                $resultText .= ' · '.number_format((int) $event['tokens']).' tok';
            }
        @endphp
        <div class="as-item as-result">
            <span class="as-ico">{{ $icons['result'] }}</span>
            <span class="as-main as-text">{{ $resultText }}</span>
        </div>
    @endif
@empty
    <div class="as-item as-argos"><span class="as-ico">│</span><span class="as-main as-text">—</span></div>
@endforelse

@if ($live)
    <div class="as-item"><span class="as-ico"></span><span class="cursor"></span></div>
@endif
