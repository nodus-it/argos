@php use App\Filament\Admin\Resources\TaskResource; @endphp
<x-filament-panels::page>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Konzept (2/3 Breite) --}}
        <div class="lg:col-span-2 flex flex-col gap-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">concept.md</span>
                    @if($hasConceptmd)
                        <span class="text-xs text-gray-400">{{ $task->name }}</span>
                    @endif
                </div>

                <div class="px-6 py-5">
                    @if($hasConceptmd)
                        <div class="prose prose-sm dark:prose-invert max-w-none
                            prose-headings:font-semibold prose-headings:text-gray-800 dark:prose-headings:text-gray-100
                            prose-p:text-gray-600 dark:prose-p:text-gray-300
                            prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                            prose-pre:bg-gray-950 prose-pre:text-gray-200
                            prose-li:text-gray-600 dark:prose-li:text-gray-300">
                            {!! Illuminate\Support\Str::markdown($conceptMarkdown) !!}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-16 text-center gap-3">
                            <x-heroicon-o-document-text class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Noch kein Konzept vorhanden.</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600">Starte die Concept-Phase um ein Konzept zu generieren.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Notes (1/3 Breite) --}}
        <div class="flex flex-col gap-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Feedback / Notes</span>
                    @if(!$editingNotes)
                        <button
                            wire:click="startEditingNotes"
                            class="text-xs text-primary-600 dark:text-primary-400 hover:underline"
                        >
                            Bearbeiten
                        </button>
                    @endif
                </div>

                <div class="px-5 py-4">
                    @if($editingNotes)
                        <textarea
                            wire:model="notes"
                            rows="12"
                            placeholder="Anmerkungen zum Konzept, Korrekturen, zusätzliche Anforderungen…"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none font-mono"
                        ></textarea>
                        <div class="flex gap-2 mt-3">
                            <button
                                wire:click="saveNotes"
                                class="flex-1 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium py-2 transition-colors"
                            >
                                Speichern
                            </button>
                            <button
                                wire:click="cancelEditingNotes"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 text-sm font-medium px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                            >
                                Abbrechen
                            </button>
                        </div>
                    @elseif($notes !== '')
                        <pre class="whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-400 font-mono leading-relaxed">{{ $notes }}</pre>
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-600 italic">
                            Notes werden beim nächsten Concept-Lauf als Feedback übergeben.
                        </p>
                    @else
                        <div class="flex flex-col items-center justify-center py-8 text-center gap-2">
                            <x-heroicon-o-pencil-square class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                            <p class="text-xs text-gray-400 dark:text-gray-500">Keine Notes vorhanden.</p>
                            <button
                                wire:click="startEditingNotes"
                                class="mt-1 text-xs text-primary-600 dark:text-primary-400 hover:underline"
                            >
                                Notes hinzufügen
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Nächste Schritte --}}
            @if($hasConceptmd)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Nächste Schritte</span>
                    </div>
                    <div class="px-5 py-4 flex flex-col gap-2">
                        <a
                            href="{{ TaskResource::getUrl('view', ['record' => $task]) }}"
                            wire:navigate
                            class="flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                        >
                            <x-heroicon-o-code-bracket class="h-4 w-4 text-gray-400 flex-shrink-0" />
                            <span>Implement starten</span>
                        </a>
                        <button
                            wire:click="$dispatch('open-modal', { id: 'run-concept-again' })"
                            onclick="document.querySelector('[data-action=runConcept]')?.click()"
                            class="flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4 text-gray-400 flex-shrink-0" />
                            <span>Konzept überarbeiten</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-filament-panels::page>
