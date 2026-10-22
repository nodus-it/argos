@php
    /** @var array{author: string, avatar?: ?string, profile?: ?string, user?: ?\App\Models\User} $who */
    $name = ($who['user'] ?? null)?->name ?? $who['author'];
@endphp
{{-- Who wrote it on the pull request: picture and name, linking to their profile there. --}}
@if (! empty($who['profile']))
    <a class="wf-pr-person" href="{{ $who['profile'] }}" target="_blank" rel="noopener noreferrer" title="{{ $who['author'] }}">
        <x-wf.avatar :user="$who['user'] ?? null" :name="$who['author']" :url="$who['avatar'] ?? null" /><strong>{{ $name }}</strong>
    </a>
@else
    <span class="wf-pr-person" title="{{ $who['author'] }}"><x-wf.avatar :user="$who['user'] ?? null" :name="$who['author']" :url="$who['avatar'] ?? null" /><strong>{{ $name }}</strong></span>
@endif
