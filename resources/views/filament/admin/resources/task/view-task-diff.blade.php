<x-filament-panels::page>

    {{-- Stat Summary --}}
    @if(trim($stat) !== '')
        <div class="rounded-xl border border-slate-600 bg-slate-800 px-4 py-3 font-mono text-xs text-slate-200 whitespace-pre-wrap leading-5">{{ $stat }}</div>
    @endif

    {{-- Diff Viewer --}}
    @if($isEmpty)
        <div class="flex flex-col items-center justify-center py-20 gap-3">
            <x-heroicon-o-check-circle class="h-12 w-12 text-emerald-500/50" />
            <p class="text-sm text-slate-400">
                Keine Änderungen gegenüber origin/{{ $task->repoProfile?->default_branch ?? 'main' }}.
            </p>
        </div>
    @else
        <div class="flex flex-col gap-2">
            @foreach($diffFiles as $file)
                <div x-data="{ open: true }"
                     class="rounded-lg border border-slate-600 overflow-hidden bg-slate-900">

                    {{-- File header --}}
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

                    {{-- Diff content --}}
                    <div x-show="open" x-collapse>
                        <table class="w-full border-collapse font-mono text-xs leading-5">
                            @foreach($file['hunks'] as $hunk)
                                {{-- Hunk header --}}
                                <tr class="bg-sky-900/40 border-t border-b border-sky-800/50">
                                    <td class="w-12 px-2 py-1 text-slate-500 select-none text-right"></td>
                                    <td class="w-12 px-2 py-1 text-slate-500 select-none text-right"></td>
                                    <td class="px-3 py-1 text-sky-300/90 font-semibold">{{ $hunk['header'] }}</td>
                                </tr>
                                {{-- Lines --}}
                                @foreach($hunk['lines'] as $dline)
                                    <tr @class([
                                        'border-b border-slate-700/50',
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

    {{-- Timestamp --}}
    <p class="text-xs text-slate-600 text-right font-mono">Geladen: {{ $updatedAt }}</p>

</x-filament-panels::page>
