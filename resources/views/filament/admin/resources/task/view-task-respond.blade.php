<x-filament-panels::page>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Feedback input (2/3 width) --}}
        <div class="lg:col-span-2 flex flex-col gap-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Review-Feedback</span>
                    <span class="text-xs text-gray-400">{{ $task->name }}</span>
                </div>

                <div class="px-5 py-5 flex flex-col gap-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Schreibe dein Feedback zum PR. Der Agent arbeitet die Punkte in den Feature-Branch ein und erstellt einen neuen Push.
                    </p>

                    <textarea
                        wire:model="feedback"
                        rows="16"
                        placeholder="z.B.: Die Methode X sollte umbenannt werden in Y. Außerdem fehlt ein Test für den Fehlerfall…"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-3 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y font-mono leading-relaxed"
                    ></textarea>

                    <div class="flex items-center justify-between">
                        <p class="text-xs text-gray-400 dark:text-gray-600">
                            Nur adressierte Punkte werden geändert — kein unrelatiertes Refactoring.
                        </p>
                        <button
                            wire:click="submitFeedback"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2.5 transition-colors"
                        >
                            <span wire:loading.remove wire:target="submitFeedback">
                                <x-heroicon-o-paper-airplane class="h-4 w-4" />
                            </span>
                            <span wire:loading wire:target="submitFeedback">
                                <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" />
                            </span>
                            <span wire:loading.remove wire:target="submitFeedback">Feedback einreichen & Respond starten</span>
                            <span wire:loading wire:target="submitFeedback">Wird gestartet…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Context sidebar (1/3 width) --}}
        <div class="flex flex-col gap-4">

            {{-- Task info --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Task-Status</span>
                </div>
                <div class="px-5 py-4 flex flex-col gap-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Phase</span>
                        <span class="font-mono text-gray-800 dark:text-gray-200">{{ $task->current_phase ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Status</span>
                        <span class="font-mono text-gray-800 dark:text-gray-200">{{ $task->current_status ?? '—' }}</span>
                    </div>
                    @if($task->feature_branch)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Branch</span>
                            <span class="font-mono text-xs text-gray-800 dark:text-gray-200 truncate ml-2">{{ $task->feature_branch }}</span>
                        </div>
                    @endif
                    @if($task->pr_url)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">PR</span>
                            <a href="{{ $task->pr_url }}" target="_blank" class="text-xs text-primary-600 dark:text-primary-400 hover:underline truncate ml-2">
                                PR öffnen
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Workflow steps --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Ablauf</span>
                </div>
                <div class="px-5 py-4">
                    <ol class="flex flex-col gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 h-4 w-4 rounded-full bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold flex-shrink-0 text-[10px]">1</span>
                            <span>Feedback eingeben und absenden</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 h-4 w-4 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold flex-shrink-0 text-[10px]">2</span>
                            <span>Respond-Phase arbeitet Feedback in Branch ein</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 h-4 w-4 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold flex-shrink-0 text-[10px]">3</span>
                            <span>Push-Phase aktualisiert PR automatisch</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 h-4 w-4 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 flex items-center justify-center font-bold flex-shrink-0 text-[10px]">4</span>
                            <span>Review im PR — ggf. weiteres Feedback</span>
                        </li>
                    </ol>
                </div>
            </div>

            {{-- Quick links --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Schnellzugriff</span>
                </div>
                <div class="px-5 py-4 flex flex-col gap-2">
                    <a
                        href="{{ \App\Filament\Admin\Resources\TaskResource::getUrl('concept', ['record' => $task]) }}"
                        wire:navigate
                        class="flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    >
                        <x-heroicon-o-document-text class="h-4 w-4 text-gray-400 flex-shrink-0" />
                        <span>Konzept ansehen</span>
                    </a>
                    <a
                        href="{{ \App\Filament\Admin\Resources\TaskResource::getUrl('logs', ['record' => $task]) }}"
                        wire:navigate
                        class="flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    >
                        <x-heroicon-o-command-line class="h-4 w-4 text-gray-400 flex-shrink-0" />
                        <span>Logs</span>
                    </a>
                </div>
            </div>
        </div>

    </div>

</x-filament-panels::page>
