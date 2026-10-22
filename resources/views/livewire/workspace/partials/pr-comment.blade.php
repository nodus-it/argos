@php
    /** @var array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at?: ?string, user?: ?\App\Models\User} $prComment */
    $when = ! empty($prComment['created_at']) ? \Illuminate\Support\Carbon::parse($prComment['created_at']) : null;
@endphp
{{-- A review comment from the pull request (read-only here), at its line. --}}
<div @class(['wf-dnote-row', 'is-'.$pos]) wire:key="prc-{{ $prComment['id'] }}">
    <div class="wf-dnote is-pr">
        <div class="wf-dnote-head">
            @include('livewire.workspace.partials.pr-person', ['who' => $prComment])
            <span class="wf-pill-tag">{{ __('workspaces.review.pr_comment') }}</span>
            @if ($when !== null)
                <a class="wf-muted wf-pr-fb-time" href="{{ $prComment['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans(short: true) }}</a>
            @endif
            <span class="wf-spacer"></span>
            @if (! empty($prComment['url']))<a class="wf-icon-btn wf-icon-btn-sm" href="{{ $prComment['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}" aria-label="{{ __('workspaces.pr.open_on_provider') }}"><x-wf.icon name="link" :size="12" :stroke="2" /></a>@endif
        </div>
        <div class="wf-dnote-body wf-md ws-md prose">{{ \App\Support\TaskEventFormatter::markdown($prComment['body']) }}</div>
        @if ($canWrite)
            <div class="wf-dnote-foot">
                <button type="button" class="wf-annot-btn" wire:click="collectPrComment(@js($prComment['id']))">{{ __('workspaces.review.collect') }}</button>
            </div>
        @endif
    </div>
</div>
