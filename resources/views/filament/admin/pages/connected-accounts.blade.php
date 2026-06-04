<x-filament-panels::page>
    <div class="space-y-6">

        @php $accounts = $this->getConnectedAccounts(); @endphp

        <x-help-hint tkey="help.oauth.overview" tone="info" />

        <x-filament::section heading="{{ __('accounts.blade.github_section') }}">
            @if ($accounts['github'])
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-connected-account-avatar :account="$accounts['github']" />
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
            @elseif (!$this->isGitHubConfigured())
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_configured') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.github_not_configured_description') }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['provider' => 'github']) }}"
                        icon="heroicon-o-shield-check"
                        size="sm"
                    >
                        {{ __('accounts.blade.create_oauth_app') }}
                    </x-filament::button>
                    <a href="{{ config('argos.docs.setup_github') }}" target="_blank" rel="noopener" class="text-xs underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                        {{ __('accounts.blade.setup_link') }} ↗
                    </a>
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

        <x-filament::section heading="{{ __('accounts.blade.gitlab_section') }}">
            @if ($accounts['gitlab'])
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-connected-account-avatar :account="$accounts['gitlab']" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $accounts['gitlab']->name ?? $accounts['gitlab']->nickname }}
                            </p>
                            @if ($accounts['gitlab']->nickname)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $accounts['gitlab']->nickname }}</p>
                            @endif
                            @if ($accounts['gitlab']->instance_url)
                                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $accounts['gitlab']->instance_url }}</p>
                            @endif
                        </div>
                        <x-filament::badge color="success">{{ __('accounts.blade.badge_connected') }}</x-filament::badge>
                    </div>

                    <x-filament::button
                        wire:click="disconnectGitLab"
                        wire:confirm="{{ __('accounts.blade.disconnect') }}?"
                        color="danger"
                        size="sm"
                    >
                        {{ __('accounts.blade.disconnect') }}
                    </x-filament::button>
                </div>
            @elseif (!$this->isGitLabConfigured())
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_configured') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.gitlab_not_configured_description') }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['provider' => 'gitlab']) }}"
                        icon="heroicon-o-shield-check"
                        size="sm"
                    >
                        {{ __('accounts.blade.create_oauth_app') }}
                    </x-filament::button>
                    <a href="{{ config('argos.docs.setup_gitlab') }}" target="_blank" rel="noopener" class="text-xs underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                        {{ __('accounts.blade.setup_link') }} ↗
                    </a>
                </div>
            @else
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_connected') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.gitlab_not_connected_description') }}
                    </span>
                </div>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('auth.gitlab.redirect') }}"
                        icon="heroicon-o-arrow-right-circle"
                    >
                        {{ __('accounts.blade.connect_gitlab') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="{{ __('accounts.blade.bitbucket_section') }}">
            @if ($accounts['bitbucket'])
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-connected-account-avatar :account="$accounts['bitbucket']" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $accounts['bitbucket']->name ?? $accounts['bitbucket']->nickname }}
                            </p>
                            @if ($accounts['bitbucket']->nickname)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $accounts['bitbucket']->nickname }}</p>
                            @endif
                        </div>
                        <x-filament::badge color="success">{{ __('accounts.blade.badge_connected') }}</x-filament::badge>
                    </div>

                    <x-filament::button
                        wire:click="disconnectBitbucket"
                        wire:confirm="{{ __('accounts.blade.disconnect') }}?"
                        color="danger"
                        size="sm"
                    >
                        {{ __('accounts.blade.disconnect') }}
                    </x-filament::button>
                </div>
            @elseif (!$this->isBitbucketConfigured())
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_configured') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.bitbucket_not_configured_description') }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['provider' => 'bitbucket']) }}"
                        icon="heroicon-o-shield-check"
                        size="sm"
                    >
                        {{ __('accounts.blade.create_oauth_app') }}
                    </x-filament::button>
                    <a href="{{ config('argos.docs.setup_bitbucket') }}" target="_blank" rel="noopener" class="text-xs underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                        {{ __('accounts.blade.setup_link') }} ↗
                    </a>
                </div>
            @else
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_connected') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.bitbucket_not_connected_description') }}
                    </span>
                </div>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('auth.bitbucket.redirect') }}"
                        icon="heroicon-o-arrow-right-circle"
                    >
                        {{ __('accounts.blade.connect_bitbucket') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="{{ __('accounts.blade.linear_section') }}">
            @if ($accounts['linear'])
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-connected-account-avatar :account="$accounts['linear']" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $accounts['linear']->name ?? $accounts['linear']->nickname }}
                            </p>
                            @if ($accounts['linear']->nickname)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $accounts['linear']->nickname }}</p>
                            @endif
                        </div>
                        <x-filament::badge color="success">{{ __('accounts.blade.badge_connected') }}</x-filament::badge>
                    </div>

                    <x-filament::button
                        wire:click="disconnectLinear"
                        wire:confirm="{{ __('accounts.blade.disconnect') }}?"
                        color="danger"
                        size="sm"
                    >
                        {{ __('accounts.blade.disconnect') }}
                    </x-filament::button>
                </div>
            @elseif (!$this->isLinearConfigured())
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_configured') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.linear_not_configured_description') }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['provider' => 'linear']) }}"
                        icon="heroicon-o-shield-check"
                        size="sm"
                    >
                        {{ __('accounts.blade.create_oauth_app') }}
                    </x-filament::button>
                    <a href="{{ config('argos.docs.setup_linear') }}" target="_blank" rel="noopener" class="text-xs underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                        {{ __('accounts.blade.setup_link') }} ↗
                    </a>
                </div>
            @else
                <div class="flex items-center gap-3">
                    <x-filament::badge color="gray">{{ __('accounts.blade.badge_not_connected') }}</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('accounts.blade.linear_not_connected_description') }}
                    </span>
                </div>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        href="{{ route('auth.linear.redirect') }}"
                        icon="heroicon-o-arrow-right-circle"
                    >
                        {{ __('accounts.blade.connect_linear') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
