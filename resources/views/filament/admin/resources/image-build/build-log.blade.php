@php
    /** @var \App\Models\WorkerImageBuild|null $record */
    $record = $getRecord();
    $log = $record?->build_log ?? '';
    $rawLines = $log === '' ? [] : explode("\n", rtrim($log));

    // Map each raw build-log line to the shared terminal line shape
    // (text + semantic class), so the build log renders exactly like the
    // task-detail log panel (<x-argos.terminal>). See ARGOS design guideline.
    $lines = [];
    foreach ($rawLines as $i => $line) {
        $class = match (true) {
            str_starts_with($line, 'MISSING ') => 'err',
            str_starts_with($line, 'ok ') => 'ok',
            str_starts_with($line, '[stack build]'),
            str_starts_with($line, '[worker build]'),
            str_starts_with($line, '[validate]') => 'accent',
            str_contains($line, 'Successfully built'),
            str_contains($line, 'Successfully tagged') => 'ok',
            str_contains($line, 'failed') || str_contains($line, 'ERROR') => 'err',
            default => 'info',
        };
        $lines[] = ['text' => $line === '' ? ' ' : $line, 'class' => $class, 'n' => $i + 1];
    }

    $title = 'argos · build · '.($record?->tag ?? '—');
@endphp

@if (count($lines) === 0)
    <x-argos.terminal :title="$title" :lines="[['text' => __('worker.image_builds.empty_log'), 'class' => 'info']]" />
@else
    <x-argos.terminal :title="$title" :lines="$lines" />
@endif
