{{-- log-terminal partial: $lines (array of {text,class}), $label (string), $isRunning (bool) --}}
<div class="rounded-b-xl overflow-hidden bg-slate-900 border-t border-slate-700">

    {{-- Title bar --}}
    <div class="flex items-center justify-between px-4 py-2 bg-slate-800 border-b border-slate-700">
        <span class="text-xs text-slate-400 font-mono">{{ $label }}</span>
        <div class="flex items-center gap-2">
            @if($isRunning)
                <span class="inline-flex items-center gap-1.5 text-xs text-amber-400 font-mono">
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    live
                </span>
            @else
                <span class="text-xs text-slate-500 font-mono">{{ count($lines) }} Zeilen</span>
            @endif
        </div>
    </div>

    {{-- Log content with auto-scroll --}}
    <div
        x-data="{
            atBottom: true,
            onScroll() {
                this.atBottom = (this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight) < 60;
            },
            scrollDown() {
                if (this.atBottom) { this.$el.scrollTop = this.$el.scrollHeight; }
            }
        }"
        x-init="scrollDown()"
        x-on:scroll="onScroll()"
        x-on:livewire:update.window.debounce.200ms="$nextTick(() => scrollDown())"
        class="overflow-y-auto font-mono text-xs leading-5 p-4"
        style="max-height: 50vh; min-height: 120px;"
    >
        @if(empty($lines))
            <p class="text-slate-600 italic">Kein Log vorhanden.</p>
        @else
            @foreach($lines as $line)
                <div class="whitespace-pre-wrap break-all {{ $line['class'] }}">{{ $line['text'] !== '' ? $line['text'] : "\u{00a0}" }}</div>
            @endforeach
            @if($isRunning)
                <div class="text-slate-400 mt-1"><span class="animate-pulse">▋</span></div>
            @endif
        @endif
    </div>
</div>
