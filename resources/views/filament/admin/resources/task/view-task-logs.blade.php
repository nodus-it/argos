<x-filament-panels::page>

    <div class="flex items-center gap-2 flex-wrap">
        @foreach(['concept' => 'Concept', 'implement' => 'Implement', 'push' => 'Push'] as $p => $label)
            @php
                $isActive   = $phase === $p;
                $hasLog     = file_exists(config('argos.config_dir') . '/tasks/' . $task->name . '/' . $p . '.bg.log');
                $isThisRunning = $task->current_status === 'running' && $task->current_phase === $p;
            @endphp
            <button
                wire:click="setPhase('{{ $p }}')"
                @class([
                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-150',
                    'bg-slate-800 text-white ring-1 ring-slate-600 shadow-md' => $isActive,
                    'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' => !$isActive,
                    'opacity-40' => !$hasLog && !$isActive,
                ])
            >
                <span>{{ $label }}</span>
                @if($isThisRunning)
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                @elseif($hasLog)
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span>
                @endif
            </button>
        @endforeach

        <div class="ml-auto flex items-center gap-3 text-xs text-slate-500">
            @if($isRunning)
                <span class="inline-flex items-center gap-1.5 text-amber-400">
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    Live
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 text-slate-500">
                    <span class="h-2 w-2 rounded-full bg-slate-600"></span>
                    Beendet
                </span>
            @endif
            <span>{{ $lineCount }} Zeilen · {{ $updatedAt }}</span>
        </div>
    </div>

    <div
        class="rounded-xl overflow-hidden border border-slate-800 shadow-2xl shadow-black/50 bg-slate-950"
        x-data="{
            atBottom: true,
            init() {
                $nextTick(() => this.scrollToBottom());
                const obs = new MutationObserver(() => this.maybeScroll());
                obs.observe(this.$refs.terminal, { childList: true, subtree: true, characterData: true });
                this._obs = obs;
            },
            destroy() { this._obs && this._obs.disconnect(); },
            scrollToBottom() {
                const el = this.$refs.terminal;
                if (el) el.scrollTop = el.scrollHeight;
            },
            maybeScroll() {
                if (this.atBottom) requestAnimationFrame(() => this.scrollToBottom());
            },
            onScroll() {
                const el = this.$refs.terminal;
                if (!el) return;
                this.atBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 40;
            }
        }"
    >
        <div class="flex items-center justify-between px-4 py-2.5 bg-slate-900 border-b border-slate-800">
            <span class="text-xs text-slate-500 font-mono">
                argos · {{ $task->name }} · {{ $phase }}
            </span>
            @if($isRunning)
                <span class="inline-flex items-center gap-1.5 text-xs text-amber-400 font-mono">
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    live
                </span>
            @else
                <span class="text-xs text-slate-600 font-mono">idle</span>
            @endif
        </div>

        <div
            x-ref="terminal"
            class="overflow-y-auto font-mono text-xs leading-5 p-4 space-y-0"
            style="height: 60vh; min-height: 400px;"
            @scroll="onScroll()"
        >
            @if(empty($lines))
                <p class="text-slate-600 italic">Kein Log vorhanden für Phase „{{ $phase }}".</p>
            @else
                @foreach($lines as $line)
                    <div class="whitespace-pre-wrap break-all {{ $line['class'] }}">{{ $line['text'] ?: '&nbsp;' }}</div>
                @endforeach

                @if($isRunning)
                    <div class="text-slate-400 mt-1">
                        <span class="animate-pulse">▋</span>
                    </div>
                @endif
            @endif
        </div>

        <div class="flex items-center justify-between px-4 py-2 bg-slate-900 border-t border-slate-800 text-xs font-mono">
            <div class="flex items-center gap-3">
                @php
                    $status = $task->current_phase === $phase ? $task->current_status : null;
                @endphp
                @if($status === 'running')
                    <span class="text-amber-400">running</span>
                @elseif($status === 'paused')
                    <span class="text-amber-400">⏸ pausiert (Turn-Limit)</span>
                @elseif($status === 'completed')
                    <span class="text-emerald-400">✓ completed</span>
                @elseif($status === 'failed' || $status === 'quality_gate_failed')
                    <span class="text-red-400">✗ {{ $status }}</span>
                @elseif($status === 'no_changes')
                    <span class="text-sky-400">— no_changes</span>
                @else
                    <span class="text-slate-600">—</span>
                @endif
            </div>
            <div class="text-slate-600">
                {{ $lineCount }} lines
            </div>
        </div>
    </div>

    {{-- Only poll while a phase is running, otherwise the terminal is static. --}}
    @if($isRunning)
        <div wire:poll.1500ms="poll" class="hidden"></div>
    @endif

</x-filament-panels::page>
