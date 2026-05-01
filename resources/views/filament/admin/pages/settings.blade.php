<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section heading="Claude OAuth Token">
            <div class="flex items-center gap-3">
                @if ($claudeTokenSet)
                    <x-filament::badge color="success">gesetzt</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Token ist über <code>CLAUDE_CODE_OAUTH_TOKEN</code> konfiguriert.
                    </span>
                @else
                    <x-filament::badge color="danger">nicht gesetzt</x-filament::badge>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Bitte <code>CLAUDE_CODE_OAUTH_TOKEN</code> in der Umgebungskonfiguration setzen.
                    </span>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Datenbank">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Aktive Verbindung: <strong>{{ $dbConnection }}</strong>
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Konfigurierbar über <code>DB_CONNECTION</code> und <code>DB_DATABASE</code>.
            </p>
        </x-filament::section>

        <x-filament::section heading="Worker Image">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <code>{{ $workerImage }}</code>
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Konfigurierbar über <code>ARGOS_WORKER_IMAGE</code>.
            </p>
        </x-filament::section>

    </div>
</x-filament-panels::page>
