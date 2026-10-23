@php
    /** @var string $provider */
    /** @var \App\Models\ConnectedAccount|null $account */
    /** @var bool $configured */
@endphp
{{-- One provider on My account → Connected accounts: connected, not configured, or ready to connect. --}}
<x-filament::section heading="{{ __('accounts.blade.'.$provider.'_section') }}">
    @if ($account)
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <x-connected-account-avatar :account="$account" />
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $account->name ?? $account->nickname }}</p>
                    @if ($account->nickname)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $account->nickname }}</p>
                    @endif
                    @if ($account->instance_url)
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $account->instance_url }}</p>
                    @endif
                </div>
                <x-filament::badge color="success">{{ __('accounts.blade.badge_connected') }}</x-filament::badge>
            </div>
            <x-filament::button wire:click="disconnect('{{ $provider }}')" wire:confirm="{{ __('accounts.blade.disconnect') }}?" color="danger" size="sm">
                {{ __('accounts.blade.disconnect') }}
            </x-filament::button>
        </div>
    @elseif (! $configured)
        <div class="flex items-center gap-3">
            <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_configured') }}</x-filament::badge>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('accounts.blade.'.$provider.'_not_configured_description') }}</span>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-4">
            <x-filament::button tag="a" href="{{ route('filament.app.system.resources.oauth-apps.create', ['provider' => $provider]) }}" icon="heroicon-o-shield-check" size="sm">
                {{ __('accounts.blade.create_oauth_app') }}
            </x-filament::button>
            <a href="{{ \App\Support\DocLink::url($provider) }}" target="_blank" rel="noopener" class="text-xs underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                {{ __('accounts.blade.setup_link') }}
            </a>
        </div>
    @else
        <div class="flex items-center gap-3">
            <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_connected') }}</x-filament::badge>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('accounts.blade.'.$provider.'_not_connected_description') }}</span>
        </div>
        <div class="mt-4">
            <x-filament::button tag="a" href="{{ route('auth.'.$provider.'.redirect') }}" icon="heroicon-o-arrow-right-circle">
                {{ __('accounts.blade.connect_'.$provider) }}
            </x-filament::button>
        </div>
    @endif
</x-filament::section>
