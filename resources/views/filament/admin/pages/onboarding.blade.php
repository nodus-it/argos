<x-filament-panels::page>

    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Step 1: environment check --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">1</span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Umgebung prüfen</span>
            </div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex items-center gap-3">
                    @if($claudeTokenSet)
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300"><code>CLAUDE_CODE_OAUTH_TOKEN</code> ist gesetzt</span>
                    @else
                        <x-heroicon-o-x-circle class="h-5 w-5 text-red-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300"><code>CLAUDE_CODE_OAUTH_TOKEN</code> fehlt — Phasen können nicht ausgeführt werden</span>
                    @endif
                </div>
                @if(!$claudeTokenSet)
                    @include('filament.admin.partials.claude-token-help')
                @endif
                <div class="flex items-center gap-3">
                    @if($workerImage)
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Worker-Image: <code>{{ $workerImage }}</code></span>
                    @else
                        <x-heroicon-o-information-circle class="h-5 w-5 text-blue-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300"><code>ARGOS_WORKER_IMAGE</code> nicht gesetzt — Standardimage wird verwendet</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Step 2: first project --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">2</span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Erstes Projekt anlegen</span>
            </div>
            <div class="px-5 py-5">
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                    Verbinde Argos mit einem Git-Repository. Weitere Projekte kannst du danach unter <strong>Konfiguration → Projekte</strong> anlegen.
                </p>

                <form wire:submit.prevent class="space-y-4">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Projektname <span class="text-red-500">*</span></label>
                        <input wire:model="name" type="text" placeholder="Mein Projekt"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Repo-URL <span class="text-red-500">*</span></label>
                        <input wire:model="url" type="url" placeholder="https://github.com/org/repo"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                        @error('url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Personal Access Token</label>
                        <input wire:model="token" type="password" placeholder="ghp_…"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                        @error('token') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Platform <span class="text-red-500">*</span></label>
                        <select wire:model="platform"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Bitte wählen…</option>
                            <option value="github">GitHub</option>
                            <option value="gitlab">GitLab</option>
                        </select>
                        @error('platform') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Branch <span class="text-red-500">*</span></label>
                        <input wire:model="default_branch" type="text" placeholder="main"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500" />
                        @error('default_branch') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                </form>
            </div>
        </div>

    </div>

</x-filament-panels::page>
