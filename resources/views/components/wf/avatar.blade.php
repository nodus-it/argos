@props(['user' => null, 'name' => null, 'class' => 'wf-avatar-xs'])
@php
    /** @var \App\Models\User|null $user */
    $label = $user?->name ?? (string) $name;
    $url = $user?->avatarUrl();
@endphp
{{-- A person's profile picture, else their initials. --}}
<span {{ $attributes->class([$class, 'has-image' => $url !== null]) }}>@if ($url !== null)<img src="{{ $url }}" alt="" loading="lazy">@else{{ \App\Support\Initials::of($label !== '' ? $label : '?') }}@endif</span>
