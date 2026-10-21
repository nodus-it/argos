@php
    /**
     * One diff row. Split: $row = ['left' => line|null, 'right' => line|null];
     * unified: $line. Comments sit on the new side, removed lines on the old side.
     */
    $sign = fn (?array $l): string => match ($l['type'] ?? null) { 'add' => '+', 'del' => '−', default => ' ' };
    $cells = $row !== null
        ? [['pos' => 'left', 'line' => $row['left'], 'side' => 'old', 'num' => $row['left']['old_num'] ?? null, 'commentable' => ($row['left']['type'] ?? '') === 'del'],
           ['pos' => 'right', 'line' => $row['right'], 'side' => 'new', 'num' => $row['right']['new_num'] ?? null, 'commentable' => $row['right'] !== null]]
        : [['pos' => 'unified', 'line' => $line, 'side' => $line['type'] === 'del' ? 'old' : 'new', 'num' => $line['type'] === 'del' ? $line['old_num'] : $line['new_num'], 'commentable' => true]];
    $after = [];
@endphp
<div @class(['wf-drow' => $row !== null, 'wf-urow' => $row === null])>
    @foreach ($cells as $cell)
        @php
            $l = $cell['line'];
            $key = $cell['side'].':'.$cell['num'];
            $isCommented = $cell['num'] !== null && in_array($key, $commented, true);
            $here = $cell['num'] !== null ? ($commentsAt[$key] ?? collect()) : collect();
            if ($here->isNotEmpty()) { $after[] = ['cell' => $cell, 'comments' => $here]; }
        @endphp
        <div @class([
                'wf-dcell', 'is-'.$cell['pos'],
                'is-add' => ($l['type'] ?? '') === 'add', 'is-del' => ($l['type'] ?? '') === 'del',
                'is-empty' => $l === null, 'is-commented' => $isCommented,
             ])
             @if ($cell['num'] !== null) :class="{ 'is-drafting': inDraft(@js($cell['side']), {{ $cell['num'] }}) }" @endif>
            @if ($row === null)
                <span class="wf-dnum">{{ $l['old_num'] ?? '' }}</span>
                <span class="wf-dnum">{{ $l['new_num'] ?? '' }}</span>
            @else
                <span class="wf-dnum">{{ $cell['num'] ?? '' }}</span>
            @endif
            <span class="wf-dtext">@if ($l !== null)<span class="wf-dsign">{{ $sign($l) }}</span>{{ $l['text'] }}@endif</span>
            @if ($here->isNotEmpty())
                <span class="wf-dbadge">{{ $here->map(fn ($c) => $numbers[$c->id] ?? '')->implode(',') }}</span>
            @elseif ($canWrite && $cell['commentable'] && $cell['num'] !== null)
                <button type="button" class="wf-dplus" x-on:click="pick(@js($cell['side']), {{ $cell['num'] }}, $event)"
                        aria-label="{{ __('workspaces.review.add_comment') }}" title="{{ __('workspaces.review.add_comment') }}">+</button>
            @endif
        </div>
    @endforeach
</div>
@foreach ($after as $entry)
    @foreach ($entry['comments'] as $comment)
        <div @class(['wf-dnote-row', 'is-'.$entry['cell']['pos']]) wire:key="rc-{{ $comment->id }}">
            <div @class(['wf-dnote', 'is-stale' => $comment->status === \App\Enums\ReviewCommentStatus::Stale]) x-data="{ editing: false, body: @js($comment->body) }">
                <div class="wf-dnote-head">
                    <span class="wf-avatar-xs">{{ \App\Support\Initials::of($comment->user->name) }}</span>
                    <strong>{{ __('workspaces.events.you') }}</strong>
                    <span class="wf-muted">{{ $comment->line_end > $comment->line_start ? $comment->location() : __('workspaces.review.in_draft') }}</span>
                    @if ($comment->status === \App\Enums\ReviewCommentStatus::Stale)
                        <span class="wf-tag is-warn">{{ __('workspaces.review.outdated') }}</span>
                    @endif
                    <span class="wf-spacer"></span>
                    <span class="wf-dbadge is-static">{{ $numbers[$comment->id] ?? '' }}</span>
                </div>
                <p class="wf-dnote-body" x-show="!editing">{{ $comment->body }}</p>
                <div x-show="editing" x-cloak class="wf-dnote-edit">
                    <textarea class="wf-input wf-dnote-ta" rows="2" x-model="body"></textarea>
                    <div class="wf-row">
                        <button type="button" class="wf-btn wf-btn-sm wf-btn-ink" x-on:click="$wire.updateComment(@js($comment->id), body); editing = false">{{ __('workspaces.review.save') }}</button>
                        <button type="button" class="wf-btn wf-btn-sm" x-on:click="editing = false; body = @js($comment->body)">{{ __('workspaces.review.cancel') }}</button>
                    </div>
                </div>
                @if ($comment->status === \App\Enums\ReviewCommentStatus::Stale)
                    @include('livewire.workspace.partials.stale-actions', ['comment' => $comment])
                @else
                    <div class="wf-row wf-dnote-actions" x-show="!editing">
                        <button type="button" class="wf-text-btn wf-muted" x-on:click="editing = true">{{ __('workspaces.review.edit') }}</button>
                        <button type="button" class="wf-text-btn wf-muted" wire:click="discardComment(@js($comment->id))">{{ __('workspaces.review.discard') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
@endforeach
@foreach ($cells as $cell)
    @if ($cell['side'] === 'new' && $cell['num'] !== null && isset($annotationsAt[$cell['num']]))
        @foreach ($annotationsAt[$cell['num']] as $finding)
            <div @class(['wf-dnote-row', 'is-'.$cell['pos']])>
                <div class="wf-annot">
                    <span class="wf-annot-gate">PHPStan</span>
                    <span class="wf-annot-msg">{{ $finding['message'] }}</span>
                    @if ($canWrite)
                        <button type="button" class="wf-annot-btn" wire:click="fixWithClaude(@js($current['path']), {{ $finding['line'] }}, 'PHPStan', @js($finding['message']))">{{ __('workspaces.review.fix') }}</button>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
    @if ($canWrite && $cell['num'] !== null && $cell['commentable'])
        <template x-if="draft && draft.side === @js($cell['side']) && draft.end === {{ $cell['num'] }}">
            <div @class(['wf-dnote-row', 'is-'.$cell['pos']])>
                <div class="wf-dnote is-draft">
                    <div class="wf-dnote-head"><strong x-text="draftLabel()"></strong></div>
                    <textarea class="wf-input wf-dnote-ta" rows="2" x-model="draft.body" x-init="$nextTick(() => $el.focus())"
                              placeholder="{{ __('workspaces.review.comment_placeholder') }}"
                              x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); save(@js($current['path'])) }"
                              x-on:keydown.escape.stop="draft = null"></textarea>
                    <div class="wf-row">
                        <button type="button" class="wf-btn wf-btn-sm wf-btn-ink" x-on:click="save(@js($current['path']))">{{ __('workspaces.review.save') }}</button>
                        <button type="button" class="wf-btn wf-btn-sm" x-on:click="draft = null">{{ __('workspaces.review.cancel') }}</button>
                    </div>
                </div>
            </div>
        </template>
    @endif
@endforeach
