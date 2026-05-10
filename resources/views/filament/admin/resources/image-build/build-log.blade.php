@php
    /** @var \App\Models\WorkerImageBuild|null $record */
    $record = $getRecord();
    $log = $record?->build_log ?? '';
    $lines = $log === '' ? [] : explode("\n", rtrim($log));
    $lineCount = count($lines);
    $status = $record?->status?->value;
@endphp

<div class="rounded-xl overflow-hidden border border-slate-800 shadow-2xl shadow-black/50 bg-slate-950">
    <div class="flex items-center justify-between px-4 py-2.5 bg-slate-900 border-b border-slate-800">
        <span class="text-xs text-slate-500 font-mono">
            argos · build · {{ $record?->tag ?? '—' }}
        </span>
        <span class="text-xs text-slate-600 font-mono">
            {{ $record?->built_at?->diffForHumans() ?? '—' }}
        </span>
    </div>

    <div
        class="overflow-y-auto font-mono text-xs leading-5 p-4 space-y-0 text-slate-100"
        style="max-height: 60vh; min-height: 200px;"
    >
        @if($lineCount === 0)
            <p class="text-slate-600 italic">{{ __('worker.image_builds.empty_log') }}</p>
        @else
            @foreach($lines as $line)
                @php
                    $class = match(true) {
                        str_starts_with($line, 'MISSING ')                       => 'text-red-400',
                        str_starts_with($line, 'ok ')                             => 'text-emerald-400',
                        str_starts_with($line, '[stack build]'),
                        str_starts_with($line, '[worker build]'),
                        str_starts_with($line, '[validate]')                      => 'text-sky-400 font-semibold',
                        str_contains($line, 'Successfully built'),
                        str_contains($line, 'Successfully tagged')                => 'text-emerald-500',
                        str_contains($line, 'failed') || str_contains($line, 'ERROR') => 'text-red-400',
                        $line === ''                                              => 'text-slate-700',
                        default                                                   => 'text-slate-300',
                    };
                @endphp
                <div class="whitespace-pre-wrap break-all {{ $class }}">{{ $line ?: ' ' }}</div>
            @endforeach
        @endif
    </div>

    <div class="flex items-center justify-between px-4 py-2 bg-slate-900 border-t border-slate-800 text-xs font-mono">
        <div class="flex items-center gap-3">
            @switch($status)
                @case('ready')
                    <span class="text-emerald-400">✓ ready</span>
                    @break
                @case('building')
                    <span class="text-amber-400">building</span>
                    @break
                @case('failed')
                    <span class="text-red-400">✗ failed</span>
                    @break
                @case('queued')
                    <span class="text-sky-400">queued</span>
                    @break
                @default
                    <span class="text-slate-600">—</span>
            @endswitch
            @if($record?->size_bytes)
                <span class="text-slate-600">·</span>
                <span class="text-slate-500">{{ number_format($record->size_bytes / 1024 / 1024, 1) }} MiB</span>
            @endif
        </div>
        <div class="text-slate-600">
            {{ $lineCount }} {{ __('worker.image_builds.lines') }}
        </div>
    </div>
</div>
