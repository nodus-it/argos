@php
    /** @var array{comments: list<array{path: string, line_start: int, line_end: int, side: string, body: string, snippet: string, outdated: bool, source_author: ?string, source_avatar: ?string, source_url: ?string}>, text: string} $review */
    $comments = $review['comments'] ?? [];
@endphp
{{-- A review handed to Claude: the collected comments as a card, then the free text. --}}
<div class="wf-review-msg">
    @if ($comments !== [])
        <div class="wf-review-msg-head">
            <x-wf.icon name="message" :size="14" :stroke="2" />
            <span>{{ trans_choice('workspaces.review.message_card', count($comments), ['count' => count($comments)]) }}</span>
        </div>
        <ol class="wf-review-msg-list">
            @foreach ($comments as $comment)
                @php $lines = $comment['line_start'] === $comment['line_end'] ? ':'.$comment['line_start'] : ':'.$comment['line_start'].'–'.$comment['line_end']; @endphp
                <li class="wf-review-msg-item" x-data="{ code: false }">
                    <div class="wf-review-msg-where">
                        <button type="button" class="wf-pr-fb-where mono" x-on:click="$dispatch('review-open', { file: @js($comment['path']) })" title="{{ $comment['path'] }}">
                            <x-wf.icon name="file" :size="11" :stroke="2" /> {{ basename($comment['path']).$lines }}
                        </button>
                        @if ($comment['side'] === 'old')<span class="wf-pill-tag">{{ __('workspaces.review.removed_line') }}</span>@endif
                        @if ($comment['outdated'])<span class="wf-pill-tag is-warn">{{ __('workspaces.review.outdated') }}</span>@endif
                        @if (! empty($comment['source_author']))
                            @include('livewire.workspace.partials.pr-person', ['who' => ['author' => $comment['source_author'], 'avatar' => $comment['source_avatar'] ?? null, 'profile' => null]])
                            @if (! empty($comment['source_url']))<a class="wf-pill-tag" href="{{ $comment['source_url'] }}" target="_blank" rel="noopener">{{ __('workspaces.review.pr_comment') }} ↗</a>@endif
                        @endif
                        @if (trim((string) $comment['snippet']) !== '')
                            <button type="button" class="wf-text-btn wf-review-msg-code-btn" x-on:click="code = ! code" :aria-expanded="code">{{ __('workspaces.review.show_code') }}</button>
                        @endif
                    </div>
                    <pre class="wf-review-msg-code mono" x-show="code" x-cloak>{{ $comment['snippet'] }}</pre>
                    <div class="wf-md ws-md prose wf-review-msg-body">{{ \App\Support\TaskEventFormatter::markdown($comment['body'], breaks: true) }}</div>
                </li>
            @endforeach
        </ol>
    @endif
    @if (($review['text'] ?? '') !== '')
        <div class="wf-msg-md wf-md ws-md prose">{{ \App\Support\TaskEventFormatter::markdown($review['text'], breaks: true) }}</div>
    @endif
</div>
