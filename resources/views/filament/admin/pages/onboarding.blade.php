<x-filament-panels::page>

    <div class="max-w-2xl mx-auto space-y-6">

        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('onboarding.intro') }}
        </p>

        @php
            $stepNumber = 1;
            $hasAnyOauth = $this->hasAnyOAuthConfigured();
        @endphp

        {{-- Step 1: Claude Token --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $tokenSource !== 'none' ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-400' : 'bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400' }} text-xs font-bold">
                    @if($tokenSource !== 'none')
                        <x-heroicon-s-check class="h-4 w-4" />
                    @else
                        {{ $stepNumber }}
                    @endif
                </span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.steps.claude_token') }}</span>
            </div>
            <div class="px-5 py-4 space-y-4">

                @if($tokenSource === 'env')
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">{!! __('onboarding.token.from_env') !!}</span>
                    </div>
                @elseif($tokenSource === 'agent_credential')
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('onboarding.token.is_saved') }}</span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('onboarding.token.override_label') }}</label>
                        <div class="flex gap-2">
                            <input wire:model="claudeToken" type="password" placeholder="{{ __('onboarding.token.placeholder') }}" autocomplete="off"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-slate-500" />
                            <x-filament::button wire:click="saveClaudeToken" type="button">
                                {{ __('onboarding.token.save_button') }}
                            </x-filament::button>
                        </div>
                    </div>
                @else
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('onboarding.token.label') }} <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input wire:model="claudeToken" type="password" placeholder="{{ __('onboarding.token.placeholder') }}" autocomplete="off"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-slate-500" />
                            <x-filament::button wire:click="saveClaudeToken" type="button">
                                {{ __('onboarding.token.save_button') }}
                            </x-filament::button>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.token.help') }}</p>
                    </div>
                    @include('filament.admin.partials.claude-token-help')
                @endif

            </div>
        </div>
        @php $stepNumber++; @endphp

        {{-- Step 2: Connect provider(s) — only if at least one OAuth provider is configured --}}
        @if ($hasAnyOauth)
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $this->isAnyProviderConnected() ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-400' : 'bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400' }} text-xs font-bold">
                        @if($this->isAnyProviderConnected())
                            <x-heroicon-s-check class="h-4 w-4" />
                        @else
                            {{ $stepNumber }}
                        @endif
                    </span>
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.steps.providers_connect') }}</span>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">{{ __('onboarding.steps.providers_optional') }}</span>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('onboarding.providers.description') }}
                    </p>

                    <div class="space-y-3">
                        @foreach ($oauthState as $provider => $state)
                            @if (! $state['configured'])
                                @continue
                            @endif

                            <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    @if ($state['connected'])
                                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                                    @else
                                        <x-heroicon-o-link class="h-5 w-5 text-gray-400 flex-shrink-0" />
                                    @endif
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ __('onboarding.providers.'.$provider) }}
                                    </span>
                                </div>
                                @if ($state['connected'])
                                    <x-filament::button
                                        wire:click="disconnectProvider('{{ $provider }}')"
                                        wire:confirm="{{ __('onboarding.providers.disconnect') }}?"
                                        color="gray"
                                        size="sm"
                                    >
                                        {{ __('onboarding.providers.disconnect') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        tag="a"
                                        href="{{ route('auth.'.$provider.'.redirect', ['return' => 'onboarding']) }}"
                                        icon="heroicon-o-arrow-right-circle"
                                        size="sm"
                                    >
                                        {{ __('onboarding.providers.connect_button', ['provider' => __('onboarding.providers.'.$provider)]) }}
                                    </x-filament::button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <x-help-hint tkey="help.oauth.available" tone="info" />
                </div>
            </div>
            @php $stepNumber++; @endphp
        @else
            {{-- No OAuth provider configured at all — show a hint instead of a step --}}
            <x-help-hint tkey="help.oauth.not_configured" tone="info" />
        @endif

        {{-- Step 3: First project --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">
                    {{ $stepNumber }}
                </span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.steps.first_project') }}</span>
            </div>
            <div class="px-5 py-4 space-y-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {!! __('onboarding.project.description') !!}
                </p>
                <x-filament::button
                    tag="a"
                    href="{{ route('filament.admin.resources.repo-profiles.create') }}"
                    icon="heroicon-o-rocket-launch"
                >
                    {{ __('onboarding.project.create_button') }}
                </x-filament::button>
            </div>
        </div>

    </div>

</x-filament-panels::page>
