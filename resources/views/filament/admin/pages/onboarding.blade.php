<x-filament-panels::page>

    @php
        $steps = [
            1 => __('onboarding.steps.agents'),
            2 => __('onboarding.steps.repository'),
            3 => __('onboarding.steps.done'),
        ];
        $furthest = $this->furthestUnlockedStep();
        $sources = $this->repoSourceOptions();
        $repoOptions = $this->currentStep === 2 ? $this->repoOptions() : [];
        $branchOptions = $this->currentStep === 2 ? $this->branchOptions() : [];
    @endphp

    <div class="max-w-6xl mx-auto w-full space-y-8">

        {{-- ── Stepper header ──────────────────────────────────────────── --}}
        <nav class="flex items-center justify-between" aria-label="Progress">
            @foreach ($steps as $number => $label)
                @php
                    $isDone = $number < $this->currentStep;
                    $isActive = $number === $this->currentStep;
                    $isReachable = $number <= $furthest;
                @endphp

                <button
                    type="button"
                    @if ($isReachable) wire:click="goToStep({{ $number }})" @endif
                    @class([
                        'flex flex-col items-center gap-2 group',
                        'cursor-pointer' => $isReachable,
                        'cursor-default' => ! $isReachable,
                    ])
                    @if (! $isReachable) disabled @endif
                >
                    <span @class([
                        'flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold ring-2 transition',
                        'bg-primary-600 text-white ring-primary-600' => $isActive,
                        'bg-emerald-500 text-white ring-emerald-500' => $isDone,
                        'bg-white dark:bg-gray-900 text-gray-400 ring-gray-300 dark:ring-gray-600' => ! $isActive && ! $isDone,
                    ])>
                        @if ($isDone)
                            <x-heroicon-s-check class="h-5 w-5" />
                        @else
                            {{ $number }}
                        @endif
                    </span>
                    <span @class([
                        'text-xs font-medium',
                        'text-primary-600 dark:text-primary-400' => $isActive,
                        'text-gray-700 dark:text-gray-300' => $isDone,
                        'text-gray-400 dark:text-gray-500' => ! $isActive && ! $isDone,
                    ])>{{ $label }}</span>
                </button>

                @if (! $loop->last)
                    <div @class([
                        'flex-1 h-0.5 mx-2 -mt-5 rounded',
                        'bg-emerald-500' => $number < $this->currentStep,
                        'bg-gray-200 dark:bg-gray-700' => $number >= $this->currentStep,
                    ])></div>
                @endif
            @endforeach
        </nav>

        {{-- ── Active step card ────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">

            {{-- Step 1: Agents --}}
            @if ($this->currentStep === 1)
                <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('onboarding.agents.heading') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('onboarding.agents.description') }}</p>
                </div>
                <div class="px-6 py-5 space-y-4">

                    {{-- Claude Code --}}
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-4 space-y-3">
                        <div class="flex items-center gap-2">
                            @if($tokenSource !== 'none')
                                <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                            @else
                                <x-heroicon-o-key class="h-5 w-5 text-gray-400 flex-shrink-0" />
                            @endif
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.agents.claude_label') }}</span>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">{!! __('onboarding.agents.claude_hint') !!}</p>
                        @if($tokenSource === 'agent_credential')
                            <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('onboarding.token.is_saved_short') }}</p>
                        @endif
                        <div class="flex gap-2">
                            <input wire:model="claudeToken" type="password" placeholder="{{ __('onboarding.token.placeholder') }}" autocomplete="off"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            <x-filament::button wire:click="saveClaudeToken" type="button">
                                {{ __('onboarding.token.save_button') }}
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Codex --}}
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-4 space-y-3">
                        <div class="flex items-center gap-2">
                            @if($codexConfigured)
                                <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                            @else
                                <x-heroicon-o-key class="h-5 w-5 text-gray-400 flex-shrink-0" />
                            @endif
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.agents.codex_label') }}</span>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">{!! __('onboarding.agents.codex_hint') !!}</p>
                        @if($codexConfigured)
                            <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('onboarding.agents.codex_saved_short') }}</p>
                        @endif
                        <div class="flex gap-2 items-start">
                            <textarea wire:model="codexAuthJson" rows="3" placeholder="{{ __('onboarding.agents.codex_placeholder') }}" autocomplete="off" spellcheck="false"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-xs font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
                            <x-filament::button wire:click="saveCodexAuthJson" type="button">
                                {{ __('onboarding.token.save_button') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between">
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('onboarding.agents.gate_hint') }}</span>
                    <x-filament::button
                        wire:click="nextStep"
                        icon="heroicon-o-arrow-right"
                        icon-position="after"
                        :disabled="! $this->isAnyAgentConfigured()"
                    >
                        {{ __('onboarding.nav.next') }}
                    </x-filament::button>
                </div>
            @endif

            {{-- Step 2: Repository --}}
            @if ($this->currentStep === 2)
                <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('onboarding.repo.heading') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('onboarding.repo.description') }}</p>
                </div>
                <div class="px-6 py-5 space-y-6">

                    {{-- 1) Authorize a git host — two equal methods --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.repo.authorize_heading') }}</h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                            {{-- Method A: OAuth --}}
                            <div class="flex flex-col rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400">
                                        <x-heroicon-o-link class="h-5 w-5" />
                                    </span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('onboarding.repo.oauth_card_title') }}</span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('onboarding.repo.oauth_card_desc') }}</p>

                                @php $oauthTargets = $this->oauthTargets(); @endphp
                                <div class="mt-auto space-y-2">
                                    @if ($oauthTargets !== [])
                                        @foreach ($oauthTargets as $t)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                                    @if ($t['connected'])
                                                        <x-heroicon-s-check-circle class="h-4 w-4 text-emerald-500" />
                                                    @endif
                                                    {{ $t['label'] }}
                                                </span>
                                                @if ($t['connected'])
                                                    <button type="button"
                                                        wire:click="disconnectTarget(@js($t['provider']), @js($t['instance_url']))"
                                                        wire:confirm="{{ __('onboarding.providers.disconnect') }}?"
                                                        class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:underline">{{ __('onboarding.providers.disconnect') }}</button>
                                                @else
                                                    <a href="{{ $t['connect_url'] }}"
                                                        class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline">{{ __('onboarding.providers.connect_button', ['provider' => $t['label']]) }} &rarr;</a>
                                                @endif
                                            </div>
                                        @endforeach

                                        {{-- Always allow adding more OAuth apps (other providers / self-hosted instances). --}}
                                        <a href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['return' => 'onboarding']) }}"
                                            class="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5" />{{ __('onboarding.repo.oauth_add_more') }}
                                        </a>
                                    @else
                                        <x-filament::button
                                            tag="a"
                                            href="{{ route('filament.admin.resources.provider-oauth-configs.create', ['return' => 'onboarding']) }}"
                                            color="gray"
                                            size="sm"
                                            icon="heroicon-o-plus"
                                        >{{ __('onboarding.repo.oauth_app_link') }}</x-filament::button>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('onboarding.repo.oauth_none') }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Method B: Personal Access Token --}}
                            <div class="flex flex-col rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 dark:bg-amber-950 text-amber-600 dark:text-amber-400">
                                        <x-heroicon-o-key class="h-5 w-5" />
                                    </span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('onboarding.repo.pat_card_title') }}</span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('onboarding.repo.pat_card_desc') }}</p>

                                @php $patTargets = $this->patTargets(); @endphp
                                <div class="mt-auto space-y-2">
                                    @foreach ($patTargets as $p)
                                        <div class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                            <x-heroicon-s-check-circle class="h-4 w-4 text-emerald-500 flex-shrink-0" />
                                            {{ $p['display'] }}
                                        </div>
                                    @endforeach

                                    <a href="{{ route('filament.admin.resources.provider-credentials.create', ['return' => 'onboarding']) }}"
                                        class="inline-flex items-center gap-1 {{ $patTargets !== [] ? 'pt-1 ' : '' }}text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline">
                                        <x-heroicon-o-plus class="h-3.5 w-3.5" />{{ __('onboarding.repo.pat_link') }}
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>

                    {{-- 2) Pick a repository --}}
                    @if ($this->hasAnyRepoSource())
                        <div class="space-y-4 border-t border-gray-100 dark:border-gray-800 pt-5">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('onboarding.repo.pick_heading') }}</h3>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('onboarding.repo.source_label') }}</label>
                                <select wire:model.live="repoSource"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <option value="">{{ __('onboarding.repo.source_placeholder') }}</option>
                                    @if ($sources['oauth'] !== [])
                                        <optgroup label="{{ __('onboarding.repo.group_oauth') }}">
                                            @foreach ($sources['oauth'] as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($sources['pat'] !== [])
                                        <optgroup label="{{ __('onboarding.repo.group_pat') }}">
                                            @foreach ($sources['pat'] as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </div>

                            {{-- Loading repos from the provider (after picking a source). --}}
                            <div wire:loading.flex wire:target="repoSource" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                {{ __('onboarding.repo.loading_repos') }}
                            </div>

                            @if ($repoSource !== '')
                                <div wire:loading.remove wire:target="repoSource">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('onboarding.repo.repo_label') }}</label>
                                    <select wire:model.live="selectedRepo"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                                        <option value="">{{ __('onboarding.repo.repo_placeholder') }}</option>
                                        @foreach ($repoOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($repoOptions === [])
                                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">{{ __('onboarding.repo.no_repos') }}</p>
                                    @endif
                                </div>
                            @endif

                            {{-- Loading branches / default branch after picking a repo. --}}
                            <div wire:loading.flex wire:target="selectedRepo" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                {{ __('onboarding.repo.loading_branches') }}
                            </div>

                            @if (is_string($selectedRepo) && $selectedRepo !== '')
                                <div wire:loading.remove wire:target="selectedRepo" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('onboarding.repo.branch_label') }}</label>
                                        <select wire:model="selectedBranch"
                                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                                            <option value="">{{ __('onboarding.repo.branch_placeholder') }}</option>
                                            @foreach ($branchOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('onboarding.repo.name_label') }}</label>
                                        <input wire:model="projectName" type="text"
                                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between">
                    <x-filament::button wire:click="prevStep" color="gray" icon="heroicon-o-arrow-left">
                        {{ __('onboarding.nav.back') }}
                    </x-filament::button>
                    <x-filament::button
                        wire:click="createProject"
                        icon="heroicon-o-rocket-launch"
                        :disabled="! is_string($selectedRepo) || $selectedRepo === '' || ! is_string($selectedBranch) || $selectedBranch === ''"
                    >
                        {{ __('onboarding.repo.create_button') }}
                    </x-filament::button>
                </div>
            @endif

            {{-- Step 3: Done --}}
            @if ($this->currentStep === 3)
                <div class="px-6 py-10 text-center space-y-4">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900">
                        <x-heroicon-o-check class="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('onboarding.done.heading') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">{{ __('onboarding.done.description') }}</p>
                    <div class="flex items-center justify-center gap-3 pt-2">
                        <x-filament::button tag="a" href="{{ route('filament.admin.resources.tasks.create') }}" icon="heroicon-o-plus">
                            {{ __('onboarding.done.create_task') }}
                        </x-filament::button>
                        @if ($createdProfileId)
                            <x-filament::button tag="a" color="gray" href="{{ route('filament.admin.resources.repo-profiles.edit', ['record' => $createdProfileId]) }}">
                                {{ __('onboarding.done.view_project') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endif

        </div>
    </div>

</x-filament-panels::page>
