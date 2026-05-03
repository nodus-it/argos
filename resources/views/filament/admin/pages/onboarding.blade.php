<x-filament-panels::page>

    <div class="max-w-2xl mx-auto space-y-6">

        <p class="text-sm text-gray-500 dark:text-gray-400">
            In drei Schritten ist Argos einsatzbereit: Claude-Token hinterlegen, optional GitHub verbinden und dann das erste Projekt anlegen.
        </p>

        {{-- Step 1: Claude Token --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $tokenSource !== 'none' ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-400' : 'bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400' }} text-xs font-bold">
                    @if($tokenSource !== 'none')
                        <x-heroicon-s-check class="h-4 w-4" />
                    @else
                        1
                    @endif
                </span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Claude Token</span>
            </div>
            <div class="px-5 py-4 space-y-4">

                @if($tokenSource === 'env')
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Token kommt aus der Umgebungsvariable <code class="text-xs">CLAUDE_CODE_OAUTH_TOKEN</code> — nichts zu tun.</span>
                    </div>
                @elseif($tokenSource === 'file')
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Token ist gespeichert.</span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Token überschreiben</label>
                        <div class="flex gap-2">
                            <input wire:model="claudeToken" type="password" placeholder="sk-ant-oat01-…" autocomplete="off"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            <button wire:click="saveClaudeToken" type="button"
                                class="rounded-lg bg-primary-600 hover:bg-primary-700 px-4 py-2 text-sm font-medium text-white">
                                Speichern
                            </button>
                        </div>
                    </div>
                @else
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Token <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input wire:model="claudeToken" type="password" placeholder="sk-ant-oat01-…" autocomplete="off"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            <button wire:click="saveClaudeToken" type="button"
                                class="rounded-lg bg-primary-600 hover:bg-primary-700 px-4 py-2 text-sm font-medium text-white">
                                Speichern
                            </button>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Wird im Config-Verzeichnis abgelegt (mode 0600).</p>
                    </div>
                    @include('filament.admin.partials.claude-token-help')
                @endif

            </div>
        </div>

        {{-- Step 2: GitHub verbinden (only if OAuth credentials configured) --}}
        @if($githubOAuthAvailable)
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $githubConnected ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-400' : 'bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400' }} text-xs font-bold">
                        @if($githubConnected)
                            <x-heroicon-s-check class="h-4 w-4" />
                        @else
                            2
                        @endif
                    </span>
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">GitHub verbinden</span>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">optional</span>
                </div>
                <div class="px-5 py-4 space-y-3">
                    @if($githubConnected)
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">GitHub-Account ist verbunden.</span>
                            </div>
                            <button wire:click="disconnectGitHub" type="button"
                                wire:confirm="GitHub-Verbindung wirklich trennen?"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300">
                                Trennen
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Tipp: Wenn du beim Verbinden keine Auswahlmaske mehr siehst, widerrufe die App zuerst auf
                            <a href="https://github.com/settings/applications" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-300">github.com/settings/applications</a>.
                        </p>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Verbinde deinen GitHub-Account per OAuth — danach kannst du Projekte ohne Personal Access Token anlegen.
                        </p>
                        <a href="{{ route('auth.github.redirect', ['return' => 'onboarding']) }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 dark:bg-gray-700 dark:hover:bg-gray-600 px-4 py-2 text-sm font-medium text-white">
                            <x-heroicon-o-arrow-right-circle class="h-4 w-4" />
                            Mit GitHub verbinden
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- Step 3: First project --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">
                    {{ $githubOAuthAvailable ? '3' : '2' }}
                </span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Erstes Projekt anlegen</span>
            </div>
            <div class="px-5 py-4 space-y-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Verbinde Argos mit einem Git-Repository. Weitere Projekte kannst du danach jederzeit unter <strong>Konfiguration → Projekte</strong> anlegen.
                </p>
                <a href="{{ route('filament.admin.resources.repo-profiles.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary-600 hover:bg-primary-700 px-4 py-2 text-sm font-medium text-white">
                    <x-heroicon-o-rocket-launch class="h-4 w-4" />
                    Projekt anlegen
                </a>
            </div>
        </div>

    </div>

</x-filament-panels::page>
