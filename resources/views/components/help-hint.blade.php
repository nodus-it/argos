@props([
    /** Translation key prefix, e.g. "help.platform.github". The component
        looks up <key>.title, <key>.body, optional <key>.link_label/url and
        <key>.doc_label/url. */
    'tkey' => null,
    /** Tone: 'info' (sky), 'tip' (amber), 'success' (emerald). */
    'tone' => 'info',
])

@php
    $hasTitle = is_string($tkey) && $tkey !== '' && trans($tkey.'.title') !== $tkey.'.title';
    $title = $hasTitle ? trans($tkey.'.title') : '';
    $body = $hasTitle ? trans($tkey.'.body') : '';

    $resolveUrl = static function (string $value): ?string {
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, ':')) {
            $key = substr($value, 1);

            // Our own docs resolve to the in-app viewer; external keys (PAT
            // settings pages, claude_setup_token, …) fall back to config.
            return \App\Support\DocLink::forDocKey($key) ?? config('argos.docs.'.$key);
        }

        return $value;
    };

    // In-app doc links (relative path) open in the same tab; external links
    // open in a new tab.
    $isInternal = static fn (?string $url): bool => is_string($url) && str_starts_with($url, '/');

    $linkLabel = $hasTitle && trans($tkey.'.link_label') !== $tkey.'.link_label'
        ? trans($tkey.'.link_label') : null;
    $linkUrl = $linkLabel !== null
        ? $resolveUrl((string) trans($tkey.'.link_url')) : null;

    $docLabel = $hasTitle && trans($tkey.'.doc_label') !== $tkey.'.doc_label'
        ? trans($tkey.'.doc_label') : null;
    $docUrl = $docLabel !== null
        ? $resolveUrl((string) trans($tkey.'.doc_url')) : null;

    $palette = match ($tone) {
        'tip' => [
            'bg' => 'bg-amber-50 dark:bg-amber-950',
            'border' => 'border-amber-200 dark:border-amber-800',
            'icon' => 'text-amber-600 dark:text-amber-400',
            'title' => 'text-amber-800 dark:text-amber-300',
            'body' => 'text-amber-900 dark:text-amber-200',
            'link' => 'text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100',
        ],
        'success' => [
            'bg' => 'bg-emerald-50 dark:bg-emerald-950',
            'border' => 'border-emerald-200 dark:border-emerald-800',
            'icon' => 'text-emerald-600 dark:text-emerald-400',
            'title' => 'text-emerald-800 dark:text-emerald-300',
            'body' => 'text-emerald-900 dark:text-emerald-200',
            'link' => 'text-emerald-700 dark:text-emerald-300 hover:text-emerald-900 dark:hover:text-emerald-100',
        ],
        default => [
            'bg' => 'bg-sky-50 dark:bg-sky-950',
            'border' => 'border-sky-200 dark:border-sky-800',
            'icon' => 'text-sky-600 dark:text-sky-400',
            'title' => 'text-sky-800 dark:text-sky-300',
            'body' => 'text-sky-900 dark:text-sky-200',
            'link' => 'text-sky-700 dark:text-sky-300 hover:text-sky-900 dark:hover:text-sky-100',
        ],
    };
@endphp

@if ($hasTitle)
    <div class="rounded-lg border {{ $palette['border'] }} {{ $palette['bg'] }} px-4 py-3">
        <div class="flex items-start gap-3">
            <x-heroicon-o-information-circle class="h-5 w-5 mt-0.5 flex-shrink-0 {{ $palette['icon'] }}" />
            <div class="flex-1 min-w-0 space-y-1">
                <p class="text-sm font-semibold {{ $palette['title'] }}">{{ $title }}</p>
                <p class="text-sm {{ $palette['body'] }}">{!! $body !!}</p>
                @if ($linkUrl || $docUrl)
                    <p class="pt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @if ($linkUrl)
                            <a href="{{ $linkUrl }}" @if ($isInternal($linkUrl)) wire:navigate @else target="_blank" rel="noopener" @endif class="underline {{ $palette['link'] }}">
                                {{ $linkLabel }} @unless ($isInternal($linkUrl)) ↗ @endunless
                            </a>
                        @endif
                        @if ($docUrl)
                            <a href="{{ $docUrl }}" @if ($isInternal($docUrl)) wire:navigate @else target="_blank" rel="noopener" @endif class="underline {{ $palette['link'] }}">
                                {{ $docLabel }} @unless ($isInternal($docUrl)) ↗ @endunless
                            </a>
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif
