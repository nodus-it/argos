<div wire:poll.60s="loadUsage" class="px-3 py-3 border-t border-gray-200 dark:border-white/10">
    @if (!$error && ($fiveHour || $sevenDay))
        <div class="space-y-2">
            @if ($fiveHour)
                <x-anthropic-usage-bar
                    label="5h Limit"
                    :utilization="$fiveHour['utilization']"
                    :resets-in="$fiveHour['resets_in']"
                />
            @endif

            @if ($sevenDay)
                <x-anthropic-usage-bar
                    label="7d Limit"
                    :utilization="$sevenDay['utilization']"
                    :resets-in="$sevenDay['resets_in']"
                />
            @endif
        </div>
    @endif
</div>
