<x-filament-panels::page>

    @if(count($phaseTabs) > 1)
        <div class="flex items-center gap-2 flex-wrap mb-3">
            @foreach($phaseTabs as $tab)
                <button
                    wire:click="switchPhase('{{ $tab['phase'] }}')"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-150',
                        'bg-slate-800 text-white ring-1 ring-slate-600 shadow-md' => $phase === $tab['phase'],
                        'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' => $phase !== $tab['phase'],
                    ])
                >
                    <span>{{ ucfirst($tab['phase']) }} #{{ $tab['iteration'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if($availableKeys === [])
        <div class="rounded-xl border border-slate-800 bg-slate-950 p-8 text-center text-slate-500">
            <p>{{ __('tasks.view.quality_gate_log.no_logs') }}</p>
            <p class="mt-2 text-xs text-slate-600">
                {{ __('tasks.view.quality_gate_log.no_logs_hint') }}
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-[14rem_minmax(0,1fr)] gap-3">
            {{-- Sidebar with available gate keys --}}
            <nav class="rounded-xl border border-slate-800 bg-slate-950 p-2 space-y-0.5 max-h-[60vh] overflow-y-auto">
                @foreach($availableKeys as $key)
                    @php
                        $parts = explode('.', $key, 2);
                        $gateName = $parts[0];
                        $iterLabel = $parts[1] ?? 'initial';
                        $isActive = $activeKey === $key;
                    @endphp
                    <button
                        wire:click="selectKey('{{ $key }}')"
                        @class([
                            'w-full text-left px-3 py-2 rounded-md text-xs font-mono transition-colors',
                            'bg-slate-800 text-white ring-1 ring-slate-700' => $isActive,
                            'text-slate-400 hover:text-slate-100 hover:bg-slate-800/50' => !$isActive,
                        ])
                    >
                        <div class="font-semibold">{{ strtoupper($gateName) }}</div>
                        <div class="text-[10px] uppercase tracking-wider opacity-70">{{ $iterLabel }}</div>
                    </button>
                @endforeach
            </nav>

            {{-- Log content --}}
            <div class="rounded-xl overflow-hidden border border-slate-800 shadow-2xl shadow-black/50 bg-slate-950">
                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-900 border-b border-slate-800">
                    <span class="text-xs text-slate-500 font-mono">
                        argos · {{ $task->name }} · {{ $phase }} #{{ $iteration }} · {{ $activeKey }}
                    </span>
                    <span class="text-xs text-slate-600 font-mono">
                        {{ number_format(strlen($logContent)) }} bytes
                    </span>
                </div>

                <div
                    class="overflow-auto font-mono text-xs leading-5 p-4"
                    style="height: 60vh; min-height: 400px;"
                >
                    @if($logContent === '')
                        <p class="text-slate-600 italic">{{ __('tasks.view.quality_gate_log.empty_content') }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all text-slate-300">{{ $logContent }}</pre>
                    @endif
                </div>
            </div>
        </div>
    @endif

</x-filament-panels::page>
