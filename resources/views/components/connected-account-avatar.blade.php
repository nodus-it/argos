@props(['account'])

@php
    $label = trim((string) ($account?->name ?? $account?->nickname ?? ''));
    $initials = collect(preg_split('/\s+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [])
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
    $initials = $initials !== '' ? $initials : '?';
    $avatarUrl = $account?->displayAvatarUrl();
@endphp

{{-- The initials sit underneath; a successfully loaded avatar covers them.
     If the image fails (relative path, auth-protected self-hosted instance,
     mixed content), onerror removes it and the initials remain visible —
     so a broken provider avatar never shows a broken-image icon. --}}
<span
    {{ $attributes->merge(['class' => 'relative inline-flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-200 text-sm font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300']) }}
>
    <span>{{ $initials }}</span>

    @if ($avatarUrl)
        <img
            src="{{ $avatarUrl }}"
            alt="{{ $label !== '' ? $label : __('accounts.blade.avatar_alt') }}"
            class="absolute inset-0 h-full w-full object-cover"
            onerror="this.remove()"
        >
    @endif
</span>
