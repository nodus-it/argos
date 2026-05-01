<x-filament-panels::page>

    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Schritt 1: Umgebung --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">1</span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Umgebung prüfen</span>
            </div>
            <div class="px-5 py-4 space-y-3">

                <div class="flex items-center gap-3">
                    @if($claudeTokenSet)
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <code>CLAUDE_CODE_OAUTH_TOKEN</code> ist gesetzt
                        </span>
                    @else
                        <x-heroicon-o-x-circle class="h-5 w-5 text-red-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <code>CLAUDE_CODE_OAUTH_TOKEN</code> fehlt — bitte in <code>.env</code> eintragen
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    @if($workerImage)
                        <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            Worker-Image: <code>{{ $workerImage }}</code>
                        </span>
                    @else
                        <x-heroicon-o-information-circle class="h-5 w-5 text-blue-500 flex-shrink-0" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <code>ARGOS_WORKER_IMAGE</code> nicht gesetzt — Standardimage wird verwendet
                        </span>
                    @endif
                </div>

            </div>
        </div>

        {{-- Schritt 2: Erstes Projekt --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-400 text-xs font-bold">2</span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Erstes Projekt anlegen</span>
            </div>
            <div class="px-5 py-5">
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Verbinde Argos mit einem Git-Repository. Du kannst danach weitere Projekte unter <strong>Konfiguration → Projekte</strong> anlegen.
                </p>

                <form wire:submit="createProject">
                    {{ $this->form }}

                    <div class="mt-5 flex justify-end">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2.5 transition-colors"
                        >
                            <span wire:loading.remove wire:target="createProject">
                                <x-heroicon-o-rocket-launch class="h-4 w-4" />
                            </span>
                            <span wire:loading wire:target="createProject">
                                <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" />
                            </span>
                            <span wire:loading.remove wire:target="createProject">Projekt anlegen & loslegen</span>
                            <span wire:loading wire:target="createProject">Wird angelegt…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</x-filament-panels::page>
