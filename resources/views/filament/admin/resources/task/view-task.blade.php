<x-filament-panels::page>

    @php
        /** @var \App\Models\Task $record */
        $statusColorMap = [
            'running'             => 'text-amber-400 bg-amber-400/10 ring-amber-400/30',
            'paused'              => 'text-amber-400 bg-amber-400/10 ring-amber-400/30',
            'completed'           => 'text-emerald-400 bg-emerald-400/10 ring-emerald-400/30',
            'failed'              => 'text-red-400 bg-red-400/10 ring-red-400/30',
            'quality_gate_failed' => 'text-red-400 bg-red-400/10 ring-red-400/30',
            'no_changes'          => 'text-sky-400 bg-sky-400/10 ring-sky-400/30',
            'pending'             => 'text-slate-400 bg-slate-400/10 ring-slate-400/30',
        ];
        $statusLabelMap = [
            'paused' => 'pausiert',
        ];
        $phaseRun = fn(string $phase) => ($phaseRuns[$phase] ?? collect())->last();
        $phaseStatus = fn(string $phase) => $phaseRun($phase)?->status ?? 'pending';

        // A phase is open if it is the current one, or if no phase has run yet
        // and we're showing concept.
        $currentPhase = $record->current_phase;
        $isOpen = fn(string $phase) => $currentPhase === $phase
            || ($currentPhase === null && $phase === 'concept');
    @endphp

    {{-- ===================== Task header ===================== --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        <div class="lg:col-span-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-6 py-5 flex flex-col gap-3">
            <div class="flex items-start gap-3">
                <x-heroicon-o-clipboard-document-list class="h-5 w-5 text-gray-400 mt-0.5 flex-shrink-0" />
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white break-words">{{ $record->name }}</h2>
                    @if($record->description)
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400 leading-relaxed whitespace-pre-wrap break-words">{{ $record->description }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-5 py-4 flex flex-col gap-3">

            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</span>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                    'text-gray-600 bg-gray-100 ring-gray-300 dark:text-gray-400 dark:bg-gray-800 dark:ring-gray-600' => $record->workflow_status->color() === 'gray',
                    'text-amber-600 bg-amber-50 ring-amber-300 dark:text-amber-400 dark:bg-amber-400/10 dark:ring-amber-400/30' => $record->workflow_status->color() === 'warning',
                    'text-blue-600 bg-blue-50 ring-blue-300 dark:text-blue-400 dark:bg-blue-400/10 dark:ring-blue-400/30' => in_array($record->workflow_status->color(), ['info', 'primary']),
                    'text-emerald-600 bg-emerald-50 ring-emerald-300 dark:text-emerald-400 dark:bg-emerald-400/10 dark:ring-emerald-400/30' => $record->workflow_status->color() === 'success',
                    'text-red-600 bg-red-50 ring-red-300 dark:text-red-400 dark:bg-red-400/10 dark:ring-red-400/30' => $record->workflow_status->color() === 'danger',
                ])>{{ $record->workflow_status->label() }}</span>
            </div>

            @if($record->repoProfile)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Repository</span>
                    <span class="text-xs text-gray-700 dark:text-gray-300 truncate text-right">{{ $record->repoProfile->name }}</span>
                </div>
            @endif

            @if($record->feature_branch)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Branch</span>
                    <code class="text-xs text-indigo-600 dark:text-indigo-400 font-mono truncate text-right">{{ $record->feature_branch }}</code>
                </div>
            @endif

            @if($record->pr_url)
                @php
                    preg_match('#/pull/(\d+)#', $record->pr_url, $prMatch);
                    $prNumber = $prMatch[1] ?? null;
                @endphp
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Pull Request</span>
                    <a href="{{ $record->pr_url }}" target="_blank"
                       class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline">
                        {{ $prNumber ? "PR #{$prNumber}" : 'Öffnen' }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                    </a>
                </div>
            @endif

            @php
                $totalCost = $phaseRuns->flatten()->sum(fn($r) => (float) $r->cost_usd);
                $totalTokens = $phaseRuns->flatten()->sum(fn($r) => ($r->input_tokens ?? 0) + ($r->output_tokens ?? 0));
            @endphp
            @if($totalCost > 0)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Kosten</span>
                    <span class="text-xs text-gray-700 dark:text-gray-300">${{ number_format($totalCost, 4) }}</span>
                </div>
            @endif
            @if($totalTokens > 0)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Tokens</span>
                    <span class="text-xs text-gray-700 dark:text-gray-300">{{ number_format($totalTokens) }}</span>
                </div>
            @endif

            @if($record->current_status === 'running')
                <div class="flex items-center gap-2 pt-1 border-t border-amber-100 dark:border-amber-900/40">
                    <span class="flex h-2 w-2 relative flex-shrink-0">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">{{ $record->current_phase }} läuft</span>
                    <span x-data="{ sec: {{ max(0, now()->timestamp - ($record->currentPhaseStartedAt()?->timestamp ?? now()->timestamp)) }} }"
                          x-init="setInterval(() => sec++, 1000)"
                          x-text="Math.floor(sec/60) + ':' + String(sec % 60).padStart(2, '0')"
                          class="ml-auto text-xs font-mono tabular-nums text-amber-500 dark:text-amber-400"></span>
                </div>
            @elseif($record->current_status === 'pending')
                <div class="flex items-center gap-2 pt-1 border-t border-sky-100 dark:border-sky-900/40">
                    <svg class="animate-spin h-3 w-3 text-sky-500 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span class="text-xs text-sky-600 dark:text-sky-400 font-medium">{{ $record->current_phase }} wartet auf Worker</span>
                </div>
            @endif

            <div class="flex items-center justify-between gap-2 pt-1 border-t border-gray-100 dark:border-gray-800">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex-shrink-0">Erstellt</span>
                <span class="text-xs text-gray-500 dark:text-gray-500">{{ $record->created_at?->format('d.m.Y H:i') }}</span>
            </div>
        </div>
    </div>

    {{-- ===================== Paused banner (max-turns) ===================== --}}
    @php
        $lastImplement = ($phaseRuns['implement'] ?? collect())->last();
        $isPaused = $lastImplement?->status === 'paused';
        $implementIterations = ($phaseRuns['implement'] ?? collect())->count();
        $turnsUsed = data_get($lastImplement?->result_json, 'num_turns');
    @endphp
    @if($isPaused)
        <div class="rounded-xl border border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 p-5 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-start gap-3 flex-1">
                <x-heroicon-o-pause-circle class="h-6 w-6 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                <div>
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                        Implementierung pausiert — Turn-Limit erreicht
                    </p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200/80">
                        @if($turnsUsed)
                            Der letzte Lauf hat <span class="font-mono">{{ $turnsUsed }}</span> Turns verbraucht.
                        @endif
                        Beim Fortsetzen wird die Claude-Sitzung mit vollem Kontext wiederaufgenommen
                        — der Workspace-Stand bleibt erhalten.
                        @if($implementIterations >= 3)
                            <br><span class="font-medium">Hinweis:</span> Bereits {{ $implementIterations }}. Iteration —
                            erwäge, das Konzept aufzuteilen statt weiter fortzusetzen.
                        @endif
                    </p>
                </div>
            </div>
            <button type="button"
                    wire:click="mountAction('continueImplement')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors flex-shrink-0">
                <x-heroicon-m-play class="h-4 w-4" />
                Fortsetzen
            </button>
        </div>
    @endif

    {{-- ===================== Concept phase ===================== --}}
    @php $cStatus = $phaseStatus('concept'); $cRun = $phaseRun('concept'); @endphp
    <div x-data="{ open: @js($isOpen('concept')) }" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">

        <button type="button" x-on:click="open = !open"
                class="w-full flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
            <div class="flex items-center gap-3">
                <x-heroicon-o-light-bulb class="h-4 w-4 text-gray-400" />
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Konzept</span>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                    $statusColorMap[$cStatus] ?? $statusColorMap['pending'],
                ])>{{ $statusLabelMap[$cStatus] ?? $cStatus }}</span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                @if($cStatus === 'running')
                    <span x-data="{ sec: {{ max(0, now()->timestamp - ($cRun?->started_at?->timestamp ?? now()->timestamp)) }} }"
                          x-init="setInterval(() => sec++, 1000)"
                          x-text="Math.floor(sec/60) + ':' + String(sec % 60).padStart(2, '0')"
                          class="font-mono tabular-nums text-amber-500"></span>
                @else
                    @if($cRun?->finished_at)
                        <span>{{ $cRun->finished_at->format('d.m. H:i') }}</span>
                    @endif
                    @if($cRun?->cost_usd > 0)
                        <span>${{ number_format((float) $cRun->cost_usd, 4) }}</span>
                    @endif
                @endif
                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
            </div>
        </button>

        @if($cStatus === 'running')
            <div class="h-0.5 bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                <div class="absolute inset-y-0 w-1/3 bg-amber-400 rounded-full"
                     style="animation: argos-sweep 1.6s ease-in-out infinite;"></div>
            </div>
        @endif

        <div x-show="open" x-collapse x-cloak>
            <div wire:key="concept-tabs-{{ $conceptHtml ? 'done' : 'pending' }}"
                 x-data="{ tab: '{{ $conceptHtml ? 'concept' : 'log' }}' }">

                <div class="flex gap-1 px-4 pt-3 border-b border-gray-100 dark:border-gray-800">
                    <button type="button"
                            x-on:click="tab = 'concept'"
                            x-bind:class="tab === 'concept' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Konzept
                    </button>
                    <button type="button"
                            x-on:click="tab = 'feedback'"
                            x-bind:class="tab === 'feedback' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Feedback
                        @if($notes !== '')
                            <span class="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                        @endif
                    </button>
                    <button type="button"
                            x-on:click="tab = 'log'"
                            x-bind:class="tab === 'log' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Log
                        @if(!empty($conceptLog))
                            <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-xs text-gray-500 dark:text-gray-400">{{ count($conceptLog) }}</span>
                        @endif
                    </button>
                </div>

                {{-- Concept tab --}}
                <div x-show="tab === 'concept'" x-cloak class="px-6 py-5 flex flex-col gap-6">
                    @if($conceptHtml)
                        <div class="prose prose-sm dark:prose-invert max-w-none
                            prose-headings:font-semibold prose-headings:text-gray-800 dark:prose-headings:text-gray-100
                            prose-p:text-gray-600 dark:prose-p:text-gray-300
                            prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                            prose-code:text-gray-800 dark:prose-code:text-gray-200
                            prose-pre:bg-gray-950 prose-pre:text-gray-200
                            [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:rounded-none [&_pre_code]:text-inherit
                            prose-li:text-gray-600 dark:prose-li:text-gray-300">
                            {!! $conceptHtml !!}
                        </div>
                    @elseif($conceptError)
                        <div class="rounded-lg border border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20 p-4">
                            <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wide mb-2">
                                Konzept-Phase fehlgeschlagen
                            </p>
                            <pre class="font-mono text-xs leading-5 text-red-900 dark:text-red-200 whitespace-pre-wrap break-all">{{ $conceptError }}</pre>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-center gap-3">
                            <x-heroicon-o-document-text class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Noch kein Konzept vorhanden.</p>
                        </div>
                    @endif

                    {{-- Earlier concept versions --}}
                    @if(!empty($conceptHistory))
                        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-3">
                                Frühere Versionen ({{ count($conceptHistory) }})
                            </p>
                            <div class="flex flex-col gap-2">
                                @foreach($conceptHistory as $entry)
                                    <div x-data="{ open: false }"
                                         class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                        <button type="button" x-on:click="open = !open"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-800/60 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-gray-400 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 prose prose-sm dark:prose-invert max-w-none
                                                prose-headings:font-semibold prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                                                prose-code:text-gray-800 dark:prose-code:text-gray-200
                                                prose-pre:bg-gray-950 prose-pre:text-gray-200
                                                [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit">
                                                {!! \Illuminate\Support\Str::markdown($entry['content']) !!}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Feedback tab --}}
                <div x-show="tab === 'feedback'" x-cloak class="divide-y divide-gray-100 dark:divide-gray-800">

                    {{-- Pending notes (editable) --}}
                    <div class="px-6 py-5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ausstehend</span>
                            @if(!$editingNotes)
                                <button type="button" wire:click="startEditingNotes"
                                        class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    <x-heroicon-o-pencil class="h-3 w-3" />
                                    {{ $notes !== '' ? 'Bearbeiten' : 'Hinzufügen' }}
                                </button>
                            @endif
                        </div>

                        @if($editingNotes)
                            <div class="flex flex-col gap-3">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Wird beim nächsten Konzept-Lauf als Korrektur-Hinweis an Claude übergeben.
                                </p>
                                <textarea
                                    wire:model="notes"
                                    rows="8"
                                    placeholder="Anmerkungen, Korrekturen, zusätzliche Anforderungen…"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none font-mono leading-relaxed"
                                ></textarea>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="saveNotes"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                                        <x-heroicon-o-check class="h-3.5 w-3.5" />
                                        Speichern
                                    </button>
                                    @if($record->current_status !== 'running' && $record->workflow_status !== \App\Enums\WorkflowStatus::Completed)
                                        <button type="button" wire:click="saveNotesAndRevise"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                                            <x-heroicon-o-light-bulb class="h-3.5 w-3.5" />
                                            Speichern &amp; Konzept überarbeiten
                                        </button>
                                    @endif
                                    <button type="button" wire:click="cancelEditingNotes"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 text-xs font-medium px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                        Abbrechen
                                    </button>
                                </div>
                            </div>
                        @elseif($notes !== '')
                            <div class="flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 px-4 py-3">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" />
                                <pre class="whitespace-pre-wrap text-sm text-amber-900 dark:text-amber-200 font-mono leading-relaxed flex-1">{{ $notes }}</pre>
                            </div>
                            @if($record->current_status !== 'running' && $record->workflow_status !== \App\Enums\WorkflowStatus::Completed)
                                <div class="flex items-center justify-between pt-3">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Bereit zur Überarbeitung</span>
                                    <button type="button" wire:click="reviseConcept"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                                        <x-heroicon-o-light-bulb class="h-3.5 w-3.5" />
                                        Konzept überarbeiten
                                    </button>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-gray-400 dark:text-gray-500 italic">Kein ausstehender Feedback-Eintrag.</p>
                        @endif
                    </div>

                    {{-- History --}}
                    @if(!empty($notesHistory))
                        <div class="px-6 py-4">
                            <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide">Verlauf ({{ count($notesHistory) }})</span>
                            <div class="mt-3 flex flex-col gap-2">
                                @foreach($notesHistory as $i => $entry)
                                    <div x-data="{ open: true }"
                                         class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                        <button type="button" x-on:click="open = !open"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-800/60 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-gray-400 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            <pre class="whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-400 font-mono leading-relaxed px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $entry['content'] }}</pre>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Log tab --}}
                <div x-show="tab === 'log'" x-cloak>
                    @include('filament.admin.resources.task.partials.log-terminal', [
                        'lines' => $conceptLog,
                        'label' => 'concept.bg.log',
                        'isRunning' => $cStatus === 'running',
                    ])
                    {{-- Earlier log iterations --}}
                    @if(!empty($conceptLogIterations))
                        <div class="border-t border-slate-800 bg-slate-950 px-4 py-3">
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">
                                Frühere Iterationen ({{ count($conceptLogIterations) }})
                            </p>
                            <div class="flex flex-col gap-2">
                                @foreach($conceptLogIterations as $iter)
                                    @php $key = "concept.{$iter}"; @endphp
                                    <div x-data="{ open: false, loaded: false }"
                                         class="rounded-lg border border-slate-800 overflow-hidden">
                                        <button type="button"
                                                x-on:click="open = !open; if (open && !loaded) { loaded = true; $wire.loadLogIteration('concept', {{ $iter }}) }"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-slate-900 hover:bg-slate-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-slate-400">Iteration {{ $iter }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-slate-500 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            @if(isset($loadedLogIterations[$key]) && !empty($loadedLogIterations[$key]))
                                                <div class="font-mono text-xs leading-5 p-4 overflow-y-auto max-h-96 bg-slate-950">
                                                    @foreach($loadedLogIterations[$key] as $line)
                                                        <div class="whitespace-pre-wrap break-all {{ $line['class'] }}">{{ $line['text'] !== '' ? $line['text'] : "\u{00a0}" }}</div>
                                                    @endforeach
                                                </div>
                                            @elseif(isset($loadedLogIterations[$key]))
                                                <p class="px-4 py-3 text-xs text-slate-500 italic bg-slate-950">Keine Einträge für Iteration {{ $iter }}.</p>
                                            @else
                                                <div class="flex items-center gap-2 px-4 py-3 bg-slate-950">
                                                    <svg class="animate-spin h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                    <span class="text-xs text-slate-500">Wird geladen…</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Implement phase ===================== --}}
    @php $iStatus = $phaseStatus('implement'); $iRun = $phaseRun('implement'); @endphp
    <div x-data="{ open: @js($isOpen('implement')) }"
         class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">

        <button type="button" x-on:click="open = !open"
                class="w-full flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
            <div class="flex items-center gap-3">
                <x-heroicon-o-code-bracket class="h-4 w-4 text-gray-400" />
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Implementierung</span>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                    $statusColorMap[$iStatus] ?? $statusColorMap['pending'],
                ])>{{ $statusLabelMap[$iStatus] ?? $iStatus }}</span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                @if($iStatus === 'running')
                    <span x-data="{ sec: {{ max(0, now()->timestamp - ($iRun?->started_at?->timestamp ?? now()->timestamp)) }} }"
                          x-init="setInterval(() => sec++, 1000)"
                          x-text="Math.floor(sec/60) + ':' + String(sec % 60).padStart(2, '0')"
                          class="font-mono tabular-nums text-amber-500"></span>
                @else
                    @if($iRun?->finished_at)
                        <span>{{ $iRun->finished_at->format('d.m. H:i') }}</span>
                    @endif
                    @if($iRun?->cost_usd > 0)
                        <span>${{ number_format((float) $iRun->cost_usd, 4) }}</span>
                    @endif
                @endif
                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
            </div>
        </button>

        @if($iStatus === 'running')
            <div class="h-0.5 bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                <div class="absolute inset-y-0 w-1/3 bg-amber-400 rounded-full"
                     style="animation: argos-sweep 1.6s ease-in-out infinite;"></div>
            </div>
        @endif

        <div x-show="open" x-collapse x-cloak>
            @php
                $implementReady = $implementSummaryNontechnicalHtml || $implementSummaryTechnicalHtml;
            @endphp
            <div wire:key="implement-tabs-{{ $implementReady ? 'done' : 'pending' }}"
                 x-data="{ tab: '{{ $implementReady ? 'implement' : 'log' }}' }">

                <div class="flex gap-1 px-4 pt-3 border-b border-gray-100 dark:border-gray-800">
                    <button type="button"
                            x-on:click="tab = 'implement'"
                            x-bind:class="tab === 'implement' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Implementierung
                    </button>
                    <button type="button"
                            x-on:click="tab = 'diff'"
                            x-bind:class="tab === 'diff' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Diff
                    </button>
                    <button type="button"
                            x-on:click="tab = 'feedback'"
                            x-bind:class="tab === 'feedback' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Feedback
                        @if($implementNotes !== '')
                            <span class="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                        @endif
                    </button>
                    <button type="button"
                            x-on:click="tab = 'log'"
                            x-bind:class="tab === 'log' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Log
                        @if(!empty($implementLog))
                            <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-xs text-gray-500 dark:text-gray-400">{{ count($implementLog) }}</span>
                        @endif
                    </button>
                </div>

                {{-- ── Implementation tab ──────────────────────────────────── --}}
                <div x-show="tab === 'implement'" x-cloak class="px-6 py-5 flex flex-col gap-6">
                    @if($implementSummaryNontechnicalHtml || $implementSummaryTechnicalHtml)

                        {{-- Sub-tabs: non-technical / technical --}}
                        <div x-data="{ view: 'nontechnical' }">
                            <div class="flex gap-2 mb-4">
                                <button type="button"
                                        x-on:click="view = 'nontechnical'"
                                        x-bind:class="view === 'nontechnical'
                                            ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-200 dark:ring-indigo-800'
                                            : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors">
                                    <x-heroicon-o-user-group class="h-3.5 w-3.5" />
                                    Inhaltliche Zusammenfassung
                                </button>
                                <button type="button"
                                        x-on:click="view = 'technical'"
                                        x-bind:class="view === 'technical'
                                            ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-200 dark:ring-indigo-800'
                                            : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors">
                                    <x-heroicon-o-code-bracket class="h-3.5 w-3.5" />
                                    Technische Zusammenfassung
                                </button>
                            </div>

                            {{-- Non-technical summary --}}
                            <div x-show="view === 'nontechnical'" x-cloak>
                                @if($implementSummaryNontechnicalHtml)
                                    <div class="prose prose-sm dark:prose-invert max-w-none
                                        prose-headings:font-semibold prose-headings:text-gray-800 dark:prose-headings:text-gray-100
                                        prose-p:text-gray-600 dark:prose-p:text-gray-300
                                        prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                                        prose-code:text-gray-800 dark:prose-code:text-gray-200
                                        prose-pre:bg-gray-950 prose-pre:text-gray-200
                                        [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit
                                        prose-li:text-gray-600 dark:prose-li:text-gray-300">
                                        {!! $implementSummaryNontechnicalHtml !!}
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">Keine inhaltliche Zusammenfassung vorhanden.</p>
                                @endif
                            </div>

                            {{-- Technical summary --}}
                            <div x-show="view === 'technical'" x-cloak>
                                @if($implementSummaryTechnicalHtml)
                                    @if($implementQualityGates)
                                        <div class="mb-4 flex flex-wrap gap-2">
                                            @foreach($implementQualityGates as $gate => $result)
                                                <span @class([
                                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset',
                                                    'text-emerald-700 bg-emerald-50 ring-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:ring-emerald-800' => $result === 'pass',
                                                    'text-red-700 bg-red-50 ring-red-200 dark:text-red-300 dark:bg-red-950/40 dark:ring-red-800' => $result === 'fail',
                                                    'text-amber-700 bg-amber-50 ring-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:ring-amber-800' => $result === 'advisory_fail',
                                                    'text-gray-500 bg-gray-50 ring-gray-200 dark:text-gray-400 dark:bg-gray-800 dark:ring-gray-700' => $result === 'skip',
                                                ])>
                                                    @if($result === 'pass')
                                                        <x-heroicon-o-check class="h-3 w-3" />
                                                    @elseif(in_array($result, ['fail', 'advisory_fail']))
                                                        <x-heroicon-o-x-mark class="h-3 w-3" />
                                                    @else
                                                        <x-heroicon-o-minus class="h-3 w-3" />
                                                    @endif
                                                    {{ strtoupper($gate) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="prose prose-sm dark:prose-invert max-w-none
                                        prose-headings:font-semibold prose-headings:text-gray-800 dark:prose-headings:text-gray-100
                                        prose-p:text-gray-600 dark:prose-p:text-gray-300
                                        prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                                        prose-code:text-gray-800 dark:prose-code:text-gray-200
                                        prose-pre:bg-gray-950 prose-pre:text-gray-200
                                        [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit
                                        prose-li:text-gray-600 dark:prose-li:text-gray-300">
                                        {!! $implementSummaryTechnicalHtml !!}
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">Keine technische Zusammenfassung vorhanden.</p>
                                @endif
                            </div>
                        </div>

                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-center gap-3">
                            <x-heroicon-o-code-bracket-square class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Noch keine Zusammenfassung vorhanden.</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Wird nach dem nächsten Implement-Lauf automatisch erstellt.</p>
                        </div>
                    @endif

                    {{-- Earlier versions --}}
                    @if(!empty($implementHistory))
                        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-3">
                                Frühere Versionen ({{ count($implementHistory) }})
                            </p>
                            <div class="flex flex-col gap-2">
                                @foreach($implementHistory as $entry)
                                    <div x-data="{ open: true, view: 'nontechnical' }"
                                         class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                        <button type="button" x-on:click="open = !open"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-800/60 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-gray-400 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            <div class="border-t border-gray-100 dark:border-gray-700 px-4 pt-3 pb-1 flex gap-2">
                                                <button type="button" x-on:click="view = 'nontechnical'"
                                                        x-bind:class="view === 'nontechnical' ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'"
                                                        class="rounded px-2 py-1 text-xs font-medium transition-colors">Inhaltlich</button>
                                                <button type="button" x-on:click="view = 'technical'"
                                                        x-bind:class="view === 'technical' ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'"
                                                        class="rounded px-2 py-1 text-xs font-medium transition-colors">Technisch</button>
                                            </div>
                                            <div x-show="view === 'nontechnical'" x-cloak
                                                 class="px-5 py-4 prose prose-sm dark:prose-invert max-w-none
                                                 prose-headings:font-semibold prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                                                 prose-pre:bg-gray-950 prose-pre:text-gray-200
                                                 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit">
                                                @if($entry['nontechnical'])
                                                    {!! \Illuminate\Support\Str::markdown($entry['nontechnical']) !!}
                                                @else
                                                    <p class="text-sm text-gray-400 italic">Keine inhaltliche Zusammenfassung.</p>
                                                @endif
                                            </div>
                                            <div x-show="view === 'technical'" x-cloak
                                                 class="px-5 py-4 prose prose-sm dark:prose-invert max-w-none
                                                 prose-headings:font-semibold prose-code:bg-gray-100 dark:prose-code:bg-gray-800 prose-code:rounded prose-code:px-1
                                                 prose-pre:bg-gray-950 prose-pre:text-gray-200
                                                 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit">
                                                @if($entry['technical'])
                                                    {!! \Illuminate\Support\Str::markdown($entry['technical']) !!}
                                                @else
                                                    <p class="text-sm text-gray-400 italic">Keine technische Zusammenfassung.</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ── Diff tab (GitHub/GitLab style) ──────────────────────── --}}
                <div x-show="tab === 'diff'" x-cloak class="p-4">
                    @if(!$diffLoaded)
                        <div class="flex flex-col items-center justify-center py-10 gap-3">
                            <x-heroicon-o-document-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Diff wird nicht automatisch geladen.</p>
                            <button type="button" wire:click="loadDiff" wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium px-4 py-2 transition-colors disabled:opacity-50">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" />
                                <span wire:loading.remove>Diff laden</span>
                                <span wire:loading>Lade…</span>
                            </button>
                        </div>
                    @elseif(empty($diffFiles))
                        <div class="flex flex-col items-center justify-center py-10 gap-3">
                            <x-heroicon-o-check-circle class="h-10 w-10 text-emerald-500/60" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Keine Änderungen gegenüber origin/{{ $record->repoProfile?->default_branch ?? 'main' }}.</p>
                        </div>
                    @else
                        @if(trim($diffStat) !== '')
                            <div class="mb-3 rounded-lg border border-slate-600 bg-slate-800 px-4 py-2.5 font-mono text-xs text-slate-200 whitespace-pre-wrap leading-5">{{ $diffStat }}</div>
                        @endif

                        <div class="flex flex-col gap-2">
                            @foreach($diffFiles as $file)
                                <div x-data="{ open: true }"
                                     class="rounded-lg border border-slate-600 overflow-hidden bg-slate-900">

                                    <button type="button" x-on:click="open = !open"
                                            class="w-full flex items-center justify-between px-4 py-2.5 bg-slate-800 hover:bg-slate-700 transition-colors text-left gap-3">
                                        <div class="flex items-center gap-2 min-w-0">
                                            @if($file['is_new'])
                                                <span class="flex-shrink-0 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-emerald-900/50 text-emerald-400 ring-1 ring-emerald-700">NEU</span>
                                            @elseif($file['is_deleted'])
                                                <span class="flex-shrink-0 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-900/50 text-red-400 ring-1 ring-red-700">GELÖSCHT</span>
                                            @endif
                                            <span class="font-mono text-xs text-slate-200 truncate">{{ $file['to_path'] ?: $file['from_path'] }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            @if($file['additions'] > 0)
                                                <span class="text-xs font-semibold text-emerald-400">+{{ $file['additions'] }}</span>
                                            @endif
                                            @if($file['deletions'] > 0)
                                                <span class="text-xs font-semibold text-red-400">-{{ $file['deletions'] }}</span>
                                            @endif
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-slate-400 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </div>
                                    </button>

                                    <div x-show="open" x-collapse>
                                        <table class="w-full border-collapse font-mono text-xs leading-5">
                                            @foreach($file['hunks'] as $hunk)
                                                <tr class="bg-sky-900/40 border-t border-b border-sky-800/50">
                                                    <td class="w-12 px-2 py-1 text-slate-500 select-none text-right"></td>
                                                    <td class="w-12 px-2 py-1 text-slate-500 select-none text-right"></td>
                                                    <td class="px-3 py-1 text-sky-300/90">
                                                        <span class="font-semibold">{{ $hunk['header'] }}</span>
                                                    </td>
                                                </tr>
                                                @foreach($hunk['lines'] as $dline)
                                                    <tr @class([
                                                        'border-b border-slate-700/50 group',
                                                        'bg-emerald-950/40 hover:bg-emerald-900/30' => $dline['type'] === 'add',
                                                        'bg-red-950/40 hover:bg-red-900/30' => $dline['type'] === 'del',
                                                        'hover:bg-slate-800/60' => $dline['type'] === 'context',
                                                    ])>
                                                        <td class="w-12 px-3 py-0 text-slate-500 select-none text-right align-top leading-5 pt-0.5">{{ $dline['old_num'] ?? '' }}</td>
                                                        <td class="w-12 px-3 py-0 text-slate-500 select-none text-right align-top leading-5 pt-0.5">{{ $dline['new_num'] ?? '' }}</td>
                                                        <td @class([
                                                            'px-3 py-0 whitespace-pre-wrap break-all align-top leading-5 pt-0.5',
                                                            'text-emerald-300' => $dline['type'] === 'add',
                                                            'text-red-300' => $dline['type'] === 'del',
                                                            'text-slate-300' => $dline['type'] === 'context',
                                                        ])><span @class([
                                                            'mr-2 select-none',
                                                            'text-emerald-500' => $dline['type'] === 'add',
                                                            'text-red-500' => $dline['type'] === 'del',
                                                            'text-slate-600' => $dline['type'] === 'context',
                                                        ])>{{ $dline['type'] === 'add' ? '+' : ($dline['type'] === 'del' ? '-' : ' ') }}</span>{{ $dline['text'] !== '' ? $dline['text'] : "\u{00a0}" }}</td>
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- ── Feedback tab ──────────────────────────────────────────── --}}
                <div x-show="tab === 'feedback'" x-cloak class="divide-y divide-gray-100 dark:divide-gray-800">

                    {{-- Pending notes (editable) --}}
                    <div class="px-6 py-5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ausstehend</span>
                            @if(!$editingImplementNotes)
                                <button type="button" wire:click="startEditingImplementNotes"
                                        class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    <x-heroicon-o-pencil class="h-3 w-3" />
                                    {{ $implementNotes !== '' ? 'Bearbeiten' : 'Hinzufügen' }}
                                </button>
                            @endif
                        </div>

                        @if($editingImplementNotes)
                            <div class="flex flex-col gap-3">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Wird beim nächsten Implement-Lauf als Korrektur-Hinweis an Claude übergeben.
                                </p>
                                <textarea
                                    wire:model="implementNotes"
                                    rows="8"
                                    placeholder="Anmerkungen, Korrekturen, zusätzliche Anforderungen für den nächsten Implement-Lauf…"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none font-mono leading-relaxed"
                                ></textarea>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="saveImplementNotes"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                                        <x-heroicon-o-check class="h-3.5 w-3.5" />
                                        Speichern
                                    </button>
                                    @if($record->current_status !== 'running' && $record->workflow_status !== \App\Enums\WorkflowStatus::Completed)
                                        <button type="button" wire:click="saveImplementNotesAndRevise"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                                            <x-heroicon-o-code-bracket class="h-3.5 w-3.5" />
                                            Speichern &amp; Implementierung überarbeiten
                                        </button>
                                    @endif
                                    <button type="button" wire:click="cancelEditingImplementNotes"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 text-xs font-medium px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                        Abbrechen
                                    </button>
                                </div>
                            </div>
                        @elseif($implementNotes !== '')
                            <div class="flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 px-4 py-3">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" />
                                <pre class="whitespace-pre-wrap text-sm text-amber-900 dark:text-amber-200 font-mono leading-relaxed flex-1">{{ $implementNotes }}</pre>
                            </div>
                        @else
                            <p class="text-sm text-gray-400 dark:text-gray-500 italic">Kein ausstehender Feedback-Eintrag.</p>
                        @endif
                    </div>

                    {{-- History --}}
                    @if(!empty($implementNotesHistory))
                        <div class="px-6 py-4">
                            <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide">Verlauf ({{ count($implementNotesHistory) }})</span>
                            <div class="mt-3 flex flex-col gap-2">
                                @foreach($implementNotesHistory as $entry)
                                    <div x-data="{ open: true }"
                                         class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                        <button type="button" x-on:click="open = !open"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-800/60 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-gray-400 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            <pre class="whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-400 font-mono leading-relaxed px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $entry['content'] }}</pre>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ── Log tab ───────────────────────────────────────────────── --}}
                <div x-show="tab === 'log'" x-cloak>
                    @include('filament.admin.resources.task.partials.log-terminal', [
                        'lines' => $implementLog,
                        'label' => 'implement.bg.log',
                        'isRunning' => $iStatus === 'running',
                    ])
                    {{-- Earlier log iterations --}}
                    @if(!empty($implementLogIterations))
                        <div class="border-t border-slate-800 bg-slate-950 px-4 py-3">
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">
                                Frühere Iterationen ({{ count($implementLogIterations) }})
                            </p>
                            <div class="flex flex-col gap-2">
                                @foreach($implementLogIterations as $iter)
                                    @php $key = "implement.{$iter}"; @endphp
                                    <div x-data="{ open: false, loaded: false }"
                                         class="rounded-lg border border-slate-800 overflow-hidden">
                                        <button type="button"
                                                x-on:click="open = !open; if (open && !loaded) { loaded = true; $wire.loadLogIteration('implement', {{ $iter }}) }"
                                                class="w-full flex items-center justify-between px-4 py-2.5 bg-slate-900 hover:bg-slate-800 transition-colors text-left">
                                            <span class="text-xs font-medium text-slate-400">Iteration {{ $iter }}</span>
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-slate-500 transition-transform duration-150 flex-shrink-0" x-bind:class="open ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="open" x-collapse>
                                            @if(isset($loadedLogIterations[$key]) && !empty($loadedLogIterations[$key]))
                                                <div class="font-mono text-xs leading-5 p-4 overflow-y-auto max-h-96 bg-slate-950">
                                                    @foreach($loadedLogIterations[$key] as $line)
                                                        <div class="whitespace-pre-wrap break-all {{ $line['class'] }}">{{ $line['text'] !== '' ? $line['text'] : "\u{00a0}" }}</div>
                                                    @endforeach
                                                </div>
                                            @elseif(isset($loadedLogIterations[$key]))
                                                <p class="px-4 py-3 text-xs text-slate-500 italic bg-slate-950">Keine Einträge für Iteration {{ $iter }}.</p>
                                            @else
                                                <div class="flex items-center gap-2 px-4 py-3 bg-slate-950">
                                                    <svg class="animate-spin h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                    <span class="text-xs text-slate-500">Wird geladen…</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Push phase ===================== --}}
    @php $pStatus = $phaseStatus('push'); $pRun = $phaseRun('push'); @endphp
    <div x-data="{ open: @js($isOpen('push')) }"
         class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">

        <button type="button" x-on:click="open = !open"
                class="w-full flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
            <div class="flex items-center gap-3">
                <x-heroicon-o-arrow-up-tray class="h-4 w-4 text-gray-400" />
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Push & Pull Request</span>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                    $statusColorMap[$pStatus] ?? $statusColorMap['pending'],
                ])>{{ $statusLabelMap[$pStatus] ?? $pStatus }}</span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                @if($pStatus === 'running')
                    <span x-data="{ sec: {{ max(0, now()->timestamp - ($pRun?->started_at?->timestamp ?? now()->timestamp)) }} }"
                          x-init="setInterval(() => sec++, 1000)"
                          x-text="Math.floor(sec/60) + ':' + String(sec % 60).padStart(2, '0')"
                          class="font-mono tabular-nums text-amber-500"></span>
                @else
                    @if($pRun?->finished_at)
                        <span>{{ $pRun->finished_at->format('d.m. H:i') }}</span>
                    @endif
                @endif
                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
            </div>
        </button>

        @if($pStatus === 'running')
            <div class="h-0.5 bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                <div class="absolute inset-y-0 w-1/3 bg-amber-400 rounded-full"
                     style="animation: argos-sweep 1.6s ease-in-out infinite;"></div>
            </div>
        @endif

        <div x-show="open" x-collapse x-cloak>
            <div wire:key="push-tabs-{{ $record->pr_url ? 'done' : 'pending' }}"
                 x-data="{ tab: '{{ $record->pr_url ? 'pr' : 'log' }}' }">

                <div class="flex gap-1 px-4 pt-3 border-b border-gray-100 dark:border-gray-800">
                    <button type="button"
                            x-on:click="tab = 'pr'"
                            x-bind:class="tab === 'pr' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Pull Request
                    </button>
                    <button type="button"
                            x-on:click="tab = 'log'"
                            x-bind:class="tab === 'log' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-3 pb-2.5 text-xs font-medium border-b-2 transition-colors">
                        Log
                        @if(!empty($pushLog))
                            <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-xs text-gray-500 dark:text-gray-400">{{ count($pushLog) }}</span>
                        @endif
                    </button>
                </div>

                <div x-show="tab === 'pr'" x-cloak class="px-6 py-5">
                    @if($record->pr_url || $record->feature_branch)
                        <div class="flex flex-col gap-4">
                            @if($record->pr_url)
                                <div class="flex items-start gap-3 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3">
                                    <x-heroicon-o-check-circle class="h-5 w-5 text-emerald-500 flex-shrink-0 mt-0.5" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">Pull Request erstellt</p>
                                        <a href="{{ $record->pr_url }}" target="_blank"
                                           class="mt-0.5 text-xs text-emerald-600 dark:text-emerald-500 hover:underline break-all">
                                            {{ $record->pr_url }}
                                        </a>
                                    </div>
                                </div>
                            @endif

                            @if($record->feature_branch)
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-20 flex-shrink-0">Branch</span>
                                    <code class="text-xs text-indigo-600 dark:text-indigo-400 font-mono bg-indigo-50 dark:bg-indigo-950/40 rounded px-2 py-1">{{ $record->feature_branch }}</code>
                                </div>
                            @endif

                            @if($pRun?->result_json)
                                @php $res = $pRun->result_json; @endphp
                                @if(!empty($res['commit_sha']))
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-20 flex-shrink-0">Commit</span>
                                        <code class="text-xs text-gray-600 dark:text-gray-400 font-mono">{{ substr($res['commit_sha'], 0, 12) }}</code>
                                    </div>
                                @endif
                                @if(!empty($res['commit_subject']))
                                    <div class="flex items-start gap-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-20 flex-shrink-0 mt-0.5">Message</span>
                                        <span class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">{{ $res['commit_subject'] }}</span>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-10 text-center gap-3">
                            <x-heroicon-o-arrow-up-tray class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">Noch kein Push durchgeführt.</p>
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'log'" x-cloak>
                    @include('filament.admin.resources.task.partials.log-terminal', [
                        'lines' => $pushLog,
                        'label' => 'push.bg.log',
                        'isRunning' => $pStatus === 'running',
                    ])
                </div>
            </div>
        </div>
    </div>

    {{-- Poll every 3s while a phase is running OR pending (job queued, worker not yet picked it up). --}}
    @if(in_array($record->current_status, ['running', 'pending'], true))
        <div wire:poll.3s="poll" class="hidden"></div>
    @endif

</x-filament-panels::page>
