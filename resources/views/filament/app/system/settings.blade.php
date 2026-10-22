<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section heading="{{ __('settings.blade.logs_section') }}">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ __('settings.blade.logs_description') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 mb-3">
                {{ __('settings.blade.logs_hint') }}
            </p>
            <x-filament::button
                tag="a"
                href="{{ route('system.log.download') }}"
                target="_blank"
                color="gray"
                icon="heroicon-o-arrow-down-tray"
                size="sm"
            >
                {{ __('settings.blade.logs_download') }}
            </x-filament::button>
        </x-filament::section>

    </div>
</x-filament-panels::page>
