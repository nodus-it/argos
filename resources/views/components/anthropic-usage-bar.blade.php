@props(['label', 'utilization', 'resetsIn'])

@php
    $color = match(true) {
        $utilization >= 90 => 'bg-red-500',
        $utilization >= 70 => 'bg-amber-400',
        default            => 'bg-emerald-500',
    };
@endphp

<div>
    <div class="flex items-center justify-between mb-0.5">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</span>
        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $utilization }}% · in {{ $resetsIn }}</span>
    </div>
    <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
        <div
            class="h-full rounded-full transition-all duration-500 {{ $color }}"
            style="width: {{ min($utilization, 100) }}%"
        ></div>
    </div>
</div>
