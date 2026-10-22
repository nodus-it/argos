@php
    /** @var array{id: string, author: string, body: string, path: ?string, line: ?int, url: ?string} $prComment */
@endphp
{{-- A review comment from the pull request (read-only here), at its line. --}}
<div @class(['wf-dnote-row', 'is-'.$pos]) wire:key="prc-{{ $prComment['id'] }}">
    <div class="wf-dnote is-pr">
        <div class="wf-dnote-head">
            <strong>{{ $prComment['author'] }}</strong>
            <span class="wf-muted">{{ __('workspaces.review.pr_comment') }}@if ($prComment['line'] !== null) · :{{ $prComment['line'] }}@endif</span>
            <span class="wf-spacer"></span>
            @if (! empty($prComment['url']))<a class="wf-link" href="{{ $prComment['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}">↗</a>@endif
            @if ($canWrite)
                <button type="button" class="wf-annot-btn" wire:click="collectPrComment(@js($prComment['id']))">{{ __('workspaces.review.collect') }}</button>
            @endif
        </div>
        <div class="wf-dnote-body wf-md ws-md prose">{{ \App\Support\TaskEventFormatter::markdown($prComment['body']) }}</div>
    </div>
</div>
