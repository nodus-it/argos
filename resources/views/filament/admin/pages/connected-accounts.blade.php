<x-filament-panels::page>
    <div class="space-y-6">

        @php $accounts = $this->getConnectedAccounts(); @endphp

        <x-filament::section heading="{{ __('accounts.blade.github_section') }}">
            @if ($accounts['github'])
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if ($accounts['github']->avatar)
                            <img src="{{ $accounts['github']->avatar }}" alt="Avatar" class="h-10 w-10 rounded-full">
                        @endif
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $accounts['github']->name ?? $accounts['github']->nickname }}
                            </p>
                            @if ($accounts['github']->nickname)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $accounts['github']->nickname }}</p>
                            @endif
                        </div>
                        <x-filament::badge color="success">{{ __('accounts.blade.badge_connected') }}</x-filament::badge>
                    </div>

                    <x-filament::button
                        wire:click="disconnectGitHub"
                        wire:confirm="{{ __('accounts.blade.disconnect') }}?"
                        color="danger"
                        size="sm"
                    >
                        {{ __('accounts.blade.disconnect') }}
                    </x-filament::button>
                </div>
            @else
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_connected') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.not_connected_description') }}
                    </span>
                </div>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('auth.github.redirect') }}"
                        icon="heroicon-o-arrow-right-circle"
                    >
                        {{ __('accounts.blade.connect_github') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
