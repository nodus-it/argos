<x-filament-panels::page>

    {{-- Stat Summary --}}
    @if(trim($stat) !== '')
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 font-mono text-xs text-slate-300 whitespace-pre-wrap">{{ $stat }}</div>
    @endif

    {{-- Diff Viewer --}}
    <div class="rounded-xl overflow-hidden border border-slate-800 shadow-2xl shadow-black/50 bg-slate-950">

        {{-- Title Bar --}}
        <div class="flex items-center justify-between px-4 py-2.5 bg-slate-900 border-b border-slate-800">
            <span class="text-xs text-slate-500 font-mono">
                argos · {{ $task->name }} · diff
            </span>
            <span class="text-xs text-slate-600 font-mono">{{ $updatedAt }}</span>
        </div>

        {{-- Diff Content --}}
        <div
            class="overflow-y-auto font-mono text-xs leading-5 p-4"
            style="height: 65vh; min-height: 400px;"
        >
            @if($isEmpty)
                <p class="text-slate-500 italic">
                    Kein Diff vorhanden. Entweder läuft noch keine Phase oder es gibt keine Änderungen gegenüber origin/{{ $task->repoProfile?->default_branch ?? 'main' }}.
                </p>
            @else
                @foreach($lines as $line)
                    <div class="whitespace-pre break-all {{ $line['class'] }}">{{ $line['text'] ?: '&nbsp;' }}</div>
                @endforeach
            @endif
        </div>

        {{-- Status Bar --}}
        <div class="flex items-center justify-between px-4 py-2 bg-slate-900 border-t border-slate-800 text-xs font-mono text-slate-600">
            <span>{{ count($lines) }} Zeilen</span>
            <span>vs origin/{{ $task->repoProfile?->default_branch ?? 'main' }}</span>
        </div>
    </div>

</x-filament-panels::page>
