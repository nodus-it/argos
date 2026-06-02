@props([
    'title' => 'worker',
    'lines' => [], // list of ['text' => ..., 'class' => info|ok|warn|err|accent|cmd, 'time' => ?, 'n' => ?]
])
{{-- Terminal / log panel — dark, monospace. §5.10. Line numbers + timestamps
     stay on one line; only the text span wraps. --}}
<div class="term">
    <div class="term-head">
        <span class="term-dots"><i style="background:#f5655b"></i><i style="background:#f5bf4f"></i><i style="background:#57c75e"></i></span>
        <span class="term-title">{{ $title }}</span>
    </div>
    <div class="term-body">
        @forelse ($lines as $i => $line)
            <div class="term-line">
                <span class="ln">{{ $line['n'] ?? $i + 1 }}</span>
                @isset($line['time'])
                    <span class="tt">{{ $line['time'] }}</span>
                @endisset
                <span class="t-{{ $line['class'] ?? 'info' }}">{{ $line['text'] ?? '' }}</span>
            </div>
        @empty
            <div class="term-line"><span class="t-info">—</span></div>
        @endforelse
    </div>
</div>
