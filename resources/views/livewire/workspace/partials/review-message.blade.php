@php
    /** @var array{comments: list<array{path: string, line_start: int, line_end: int, side: string, body: string, snippet: string, outdated: bool, source_author: ?string, source_avatar: ?string, source_url: ?string}>, text: string} $review */
    $comments = $review['comments'] ?? [];
@endphp
{{-- A review handed to Claude: the collected comments as a card, then the free text. --}}
<div class="ag-review-msg">
    @if ($comments !== [])
        <div class="ag-review-msg-head">
            <x-ag.icon name="message" :size="14" :stroke="2" />
            <span>{{ trans_choice('workspaces.review.message_card', count($comments), ['count' => count($comments)]) }}</span>
        </div>
        <ol class="ag-review-msg-list">
            @foreach ($comments as $comment)
                @php $lines = $comment['line_start'] === $comment['line_end'] ? ':'.$comment['line_start'] : ':'.$comment['line_start'].'–'.$comment['line_end']; @endphp
                <li class="ag-review-msg-item" x-data="{ code: false }">
                    <div class="ag-review-msg-where">
                        <button type="button" class="ag-pr-fb-where mono" x-on:click="$dispatch('review-open', { file: @js($comment['path']) })" title="{{ $comment['path'] }}">
                            <x-ag.icon name="file" :size="11" :stroke="2" /> {{ basename($comment['path']).$lines }}
                        </button>
                        @if ($comment['side'] === 'old')<span class="ag-pill-tag">{{ __('workspaces.review.removed_line') }}</span>@endif
                        @if ($comment['outdated'])<span class="ag-pill-tag is-warn">{{ __('workspaces.review.outdated') }}</span>@endif
                        @if (! empty($comment['source_author']))
                            @include('livewire.workspace.partials.pr-person', ['who' => ['author' => $comment['source_author'], 'avatar' => $comment['source_avatar'] ?? null, 'profile' => null]])
                            @if (! empty($comment['source_url']))<a class="ag-pill-tag" href="{{ $comment['source_url'] }}" target="_blank" rel="noopener">{{ __('workspaces.review.pr_comment') }} ↗</a>@endif
                        @endif
                        @if (trim((string) $comment['snippet']) !== '')
                            <button type="button" class="ag-text-btn ag-review-msg-code-btn" x-on:click="code = ! code" :aria-expanded="code">{{ __('workspaces.review.show_code') }}</button>
                        @endif
                    </div>
                    <pre class="ag-review-msg-code mono" x-show="code" x-cloak>{{ $comment['snippet'] }}</pre>
                    <div class="ag-md ws-md prose ag-review-msg-body">{{ \App\Support\TaskEventFormatter::markdown($comment['body'], breaks: true) }}</div>
                </li>
            @endforeach
        </ol>
    @endif
    @if (($review['text'] ?? '') !== '')
        <div class="ag-msg-md ag-md ws-md prose">{{ \App\Support\TaskEventFormatter::markdown($review['text'], breaks: true) }}</div>
    @endif
</div>
