@props([
    'phase' => 'draft',  // phase icon for the timeline node, or 'you' for a user entry
    'done' => false,     // green-filled node
    'title' => '',
    'who' => null,       // "Du" / "Claude Code" pill
    'cost' => null,      // green mono cost
    'time' => null,      // mono timestamp
])
{{--
    One thread entry: a timeline node (left) + a card (right). The action
    buttons go in the `actions` slot (their own row); any expanded
    concept/diff/terminal goes in the `detail` slot, which renders as a
    separate block BELOW the buttons — never in the same flex row (§5.7).
--}}
@php
    $phaseIcons = [
        'draft' => 'heroicon-o-document-text',
        'concept' => 'heroicon-o-light-bulb',
        'implement' => 'heroicon-o-code-bracket',
        'push' => 'heroicon-o-arrow-up-tray',
        'demo' => 'heroicon-o-globe-alt',
        'review' => 'heroicon-o-chat-bubble-left-right',
        'respond' => 'heroicon-o-chat-bubble-left-right',
    ];
@endphp
<div {{ $attributes->merge(['class' => 'feed-item']) }}>
    @if ($phase === 'you')
        <div class="feed-node st-you"><x-argos.avatar>Du</x-argos.avatar></div>
    @else
        <div @class(['feed-node', 'st-done' => $done])>
            @svg($phaseIcons[$phase] ?? $phaseIcons['draft'])
        </div>
    @endif

    <div class="card feed-card">
        <div class="feed-head">
            <span class="feed-title">{{ $title }}</span>
            <span class="feed-meta">
                @if ($cost) <span class="t-ok mono">{{ $cost }}</span> @endif
                @if ($time) <span class="mono">{{ $time }}</span> @endif
                @if ($who) <span class="feed-who">{{ $who }}</span> @endif
            </span>
        </div>

        @if (trim($slot) !== '')
            <p class="feed-body">{{ $slot }}</p>
        @endif

        @isset($actions)
            <div class="feed-actions">{{ $actions }}</div>
        @endisset

        @isset($detail)
            <div class="feed-detail">{{ $detail }}</div>
        @endisset
    </div>
</div>
