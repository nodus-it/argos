<div wire:poll.30s="refresh">
    @if($active)
        <div class="mx-6 mt-4 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
            <div class="flex-1 leading-snug">
                <span class="font-semibold">Claude Usage Limit aktiv</span>
                — Tasks werden zurückgehalten und automatisch neu geplant.
                @if($resetsIn)
                    Reset in <span class="font-semibold">{{ $resetsIn }}</span>@if($resetAt)
                        &nbsp;(ca. {{ $resetAt }})@endif.
                @else
                    Retry alle 15&nbsp;min.
                @endif
            </div>
            <button
                wire:click="dismiss"
                class="shrink-0 rounded p-1 hover:bg-amber-100 dark:hover:bg-amber-900"
                title="Schließen"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" />
            </button>
        </div>
    @endif
</div>
