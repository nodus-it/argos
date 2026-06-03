@props([
    'files' => [], // ViewTask::$diffFiles shape: [from_path,to_path,is_new,is_deleted,additions,deletions,hunks[]]
])
{{-- Diff viewer — one box per file (§5.9), built on the .diff CSS classes. --}}
@forelse ($files as $file)
    <div class="diff" style="margin-bottom:8px;">
        <div class="diff-file">
            @svg('heroicon-o-document-text')
            @if ($file['is_new'] ?? false)
                <span class="chip" style="padding:1px 7px;">{{ __('tasks.view.diff.badge_new') }}</span>
            @elseif ($file['is_deleted'] ?? false)
                <span class="chip" style="padding:1px 7px;">{{ __('tasks.view.diff.badge_deleted') }}</span>
            @endif
            <span class="path">{{ $file['to_path'] ?: $file['from_path'] }}</span>
            <span>
                <span class="stat-add">+{{ $file['additions'] ?? 0 }}</span>
                <span class="stat-del">−{{ $file['deletions'] ?? 0 }}</span>
            </span>
        </div>
        <div class="diff-body">
            @foreach ($file['hunks'] as $hunk)
                <div class="diff-row hunk">
                    <span class="gut"><span></span><span></span></span>
                    <span class="code">{{ $hunk['header'] }}</span>
                </div>
                @foreach ($hunk['lines'] as $line)
                    <div @class(['diff-row', 'add' => $line['type'] === 'add', 'del' => $line['type'] === 'del'])>
                        <span class="gut"><span>{{ $line['old_num'] ?? '' }}</span><span>{{ $line['new_num'] ?? '' }}</span></span>
                        <span class="code">{{ $line['type'] === 'add' ? '+' : ($line['type'] === 'del' ? '-' : ' ') }}{{ $line['text'] }}</span>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@empty
    <div class="callout callout-info">@svg('heroicon-o-check-circle') {{ __('tasks.view.diff.no_changes', ['branch' => 'main']) }}</div>
@endforelse
