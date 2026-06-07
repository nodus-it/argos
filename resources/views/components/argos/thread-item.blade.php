@props([
    'phase' => 'draft',  // phase icon for the timeline node, or 'you' for a user entry
    'done' => false,     // green-filled node (shorthand for state="done")
    'state' => null,     // done | running | failed | paused | queued — drives node colour
    'title' => '',
    'who' => null,       // "Du" / "Claude Code" pill
    'cost' => null,      // green mono cost
    'time' => null,      // mono timestamp
])
@php
    // Normalise the node state: explicit `state` wins, else fall back to the
    // `done` shorthand. Maps to the st-* feed-node classes.
    $nodeState = $state ?? ($done ? 'done' : null);
    $nodeClass = match ($nodeState) {
        'done' => 'st-done',
        'running' => 'st-run',
        'failed' => 'st-fail',
        'paused', 'queued' => 'st-wait',
        default => null,
    };
@endphp
{{--
    One thread entry: a timeline node (left) + a card (right). The action
    buttons go in the `actions` slot (their own row); any expanded
    concept/diff/terminal goes in the `detail` slot, which renders as a
    separate block BELOW the buttons — never in the same flex row (§5.7).
--}}
<div {{ $attributes->merge(['class' => 'feed-item']) }}>
    @if ($phase === 'you')
        <div class="feed-node st-you"><x-argos.avatar>Du</x-argos.avatar></div>
    @else
        <div @class(['feed-node', $nodeClass => $nodeClass !== null])>
            @svg(\App\Support\PhaseGlyph::icon($phase))
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
