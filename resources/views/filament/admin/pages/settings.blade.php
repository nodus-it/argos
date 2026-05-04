<x-filament-panels::page>
    <div class="space-y-6">

        <div class="flex items-center gap-3">
            @if ($tokenSource === 'env')
                <x-filament::badge color="success">{{ __('settings.blade.badge_set') }}</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {!! __('settings.blade.token_from_env') !!}
                </span>
            @elseif ($tokenSource === 'file')
                <x-filament::badge color="success">{{ __('settings.blade.badge_set') }}</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('settings.blade.token_from_file') }}
                </span>
            @else
                <x-filament::badge color="danger">{{ __('settings.blade.badge_not_set') }}</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('settings.blade.token_missing') }}
                </span>
            @endif
        </div>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($this->getFormActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </form>

        @if ($tokenSource === 'none')
            @include('filament.admin.partials.claude-token-help')
        @endif

        <x-filament::section heading="{{ __('settings.blade.db_section') }}">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {!! __('settings.blade.db_connection', ['connection' => $dbConnection]) !!}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {!! __('settings.blade.db_config_hint') !!}
            </p>
        </x-filament::section>

        <x-filament::section heading="{{ __('settings.blade.worker_section') }}">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <code>{{ $workerImage }}</code>
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {!! __('settings.blade.worker_config_hint') !!}
            </p>
        </x-filament::section>

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
