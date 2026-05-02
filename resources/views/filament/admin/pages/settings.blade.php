<x-filament-panels::page>
    <div class="space-y-6">

        <div class="flex items-center gap-3">
            @if ($tokenSource === 'env')
                <x-filament::badge color="success">gesetzt</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Token kommt aus <code>CLAUDE_CODE_OAUTH_TOKEN</code> (ENV).
                </span>
            @elseif ($tokenSource === 'file')
                <x-filament::badge color="success">gesetzt</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Token ist im Config-Verzeichnis hinterlegt.
                </span>
            @else
                <x-filament::badge color="danger">nicht gesetzt</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Phasen können nicht ausgeführt werden, bis ein Token hinterlegt ist.
                </span>
            @endif
        </div>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($this->getFormActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </form>

        @if ($tokenSource === 'none')
            @include('filament.admin.partials.claude-token-help')
        @endif

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
