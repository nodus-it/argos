<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">

        <x-filament::section heading="Claude OAuth Token">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Claude OAuth Token
                    </label>
                    <input
                        type="password"
                        wire:model="claudeToken"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="oauth_..."
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Token für die Claude API-Authentifizierung.
                    </p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Datenbank (optional)">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Host
                    </label>
                    <input
                        type="text"
                        wire:model="dbHost"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="127.0.0.1"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Port
                    </label>
                    <input
                        type="text"
                        wire:model="dbPort"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="3306"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Datenbank
                    </label>
                    <input
                        type="text"
                        wire:model="dbDatabase"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="argos"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Benutzername
                    </label>
                    <input
                        type="text"
                        wire:model="dbUsername"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="argos"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Passwort
                    </label>
                    <input
                        type="password"
                        wire:model="dbPassword"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                        placeholder="••••••••"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Leer lassen um SQLite (Standard) zu verwenden.
                    </p>
                </div>
            </div>
        </x-filament::section>

        <div class="flex justify-end">
            <x-filament::button type="submit">
                Speichern
            </x-filament::button>
        </div>

    </form>
</x-filament-panels::page>
