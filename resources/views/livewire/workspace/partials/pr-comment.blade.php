@php
    /** @var array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at?: ?string, user?: ?\App\Models\User} $prComment */
    $when = ! empty($prComment['created_at']) ? \Illuminate\Support\Carbon::parse($prComment['created_at']) : null;
@endphp
{{-- A review comment from the pull request (read-only here), at its line. --}}
<div @class(['ag-dnote-row', 'is-'.$pos]) wire:key="prc-{{ $prComment['id'] }}">
    <div class="ag-dnote is-pr">
        <div class="ag-dnote-head">
            @include('livewire.workspace.partials.pr-person', ['who' => $prComment])
            <span class="ag-pill-tag">{{ __('workspaces.review.pr_comment') }}</span>
            @if ($when !== null)
                <a class="ag-muted ag-pr-fb-time" href="{{ $prComment['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans(short: true) }}</a>
            @endif
            <span class="ag-spacer"></span>
            @if (! empty($prComment['url']))<a class="ag-icon-btn ag-icon-btn-sm" href="{{ $prComment['url'] }}" target="_blank" rel="noopener" title="{{ __('workspaces.pr.open_on_provider') }}" aria-label="{{ __('workspaces.pr.open_on_provider') }}"><x-ag.icon name="link" :size="12" :stroke="2" /></a>@endif
        </div>
        <div class="ag-dnote-body ag-md ws-md prose">{{ \App\Support\TaskEventFormatter::markdown($prComment['body']) }}</div>
        @if ($canWrite)
            <div class="ag-dnote-foot">
                <button type="button" class="ag-annot-btn" wire:click="collectPrComment(@js($prComment['id']))">{{ __('workspaces.review.collect') }}</button>
            </div>
        @endif
    </div>
</div>
