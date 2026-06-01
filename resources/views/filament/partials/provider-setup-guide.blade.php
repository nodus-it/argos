{{--
    Inline provider setup guidance.
    Vars: $url (?string), $buttonLabel, $scopes, $scopesLabel, $note (?string)
--}}
<div class="rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950 px-4 py-3 space-y-2">
    @if ($url)
        <a href="{{ $url }}" target="_blank" rel="noopener"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-sky-700 dark:text-sky-300 hover:underline">
            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
            {{ $buttonLabel }}
        </a>
    @endif

    <p class="text-xs text-sky-800 dark:text-sky-300">
        <span class="font-semibold">{{ $scopesLabel }}:</span> <code class="text-xs">{{ $scopes }}</code>
    </p>

    @if (! empty($note))
        <p class="text-xs text-sky-700 dark:text-sky-400">{{ $note }}</p>
    @endif
</div>
