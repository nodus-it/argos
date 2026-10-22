@props(['user' => null, 'name' => null, 'url' => null, 'class' => 'wf-avatar-xs'])
@php
    /** @var \App\Models\User|null $user */
    $label = $user?->name ?? (string) $name;
    // Argos' own picture first, else one from elsewhere (e.g. the git provider).
    $url = $user?->avatarUrl() ?? $url;
@endphp
{{-- A person's profile picture, else their initials. --}}
<span {{ $attributes->class([$class, 'has-image' => $url !== null]) }}>@if ($url !== null)<img src="{{ $url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else{{ \App\Support\Initials::of($label !== '' ? $label : '?') }}@endif</span>
