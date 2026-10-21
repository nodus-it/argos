@php
    /** @var list<array{id: int, state: string, verb: string, target: string, add: int|null, del: int|null, detail: string, input: mixed, output: string, at: string|null}> $rows */
    $limit = 3;
    $hidden = max(0, count($rows) - $limit);
    // Finished turns show the first steps, a running turn the latest ones.
    $shown = fn (int $i): bool => $hidden === 0 || ($running ? $i >= $hidden : $i < $limit);
@endphp
{{-- Tool calls as a compact timeline: state, verb, target, +/− or result. --}}
<ol class="wf-steps" x-data="{ all: false }" wire:key="steps-{{ $rows[0]['id'] }}">
    @if ($running && $hidden > 0)
        <li class="wf-step" x-show="!all">
            <span class="wf-step-icon is-count" aria-hidden="true">+{{ $hidden }}</span>
            <button type="button" class="wf-step-more-btn" x-on:click="all = true">{{ trans_choice('workspaces.steps.more', $hidden, ['count' => $hidden]) }}</button>
        </li>
    @endif
    @foreach ($rows as $i => $row)
        <li class="wf-step is-{{ $row['state'] }}" @unless ($shown($i)) x-show="all" x-cloak @endunless x-data="{ open: false }">
            <button type="button" class="wf-step-row" x-on:click="open = !open" :aria-expanded="open" title="{{ __('workspaces.steps.details') }}">
                <span class="wf-step-icon" aria-hidden="true">
                    @switch ($row['state'])
                        @case ('ok') <x-wf.icon name="check" :size="12" :stroke="2.6" /> @break
                        @case ('err') <x-wf.icon name="x" :size="12" :stroke="2.6" /> @break
                        @case ('run') <span class="wf-step-pulse"></span> @break
                        @default <span class="wf-step-dot"></span>
                    @endswitch
                </span>
                <span class="wf-step-verb">{{ $row['verb'] }}</span>
                @if ($row['target'] !== '')<span class="wf-step-target mono">{{ $row['target'] }}</span>@endif
                @if ($row['add'] !== null && $row['state'] !== 'run')
                    <span class="wf-step-stat mono">@if ($row['add'] > 0)<span class="wf-add">+{{ $row['add'] }}</span>@endif @if ($row['del'] > 0)<span class="wf-del">−{{ $row['del'] }}</span>@endif</span>
                @endif
                @if ($row['detail'] !== '')<span class="wf-step-detail">{{ $row['detail'] }}</span>@endif
                @if (! empty($row['at']))<time class="wf-step-time mono">{{ $row['at'] }}</time>@endif
            </button>
            <div class="wf-step-body mono" x-show="open" x-cloak>{{ \App\Support\TaskEventFormatter::json($row['input']) }}@if ($row['output'] !== '')

────
{{ \Illuminate\Support\Str::limit($row['output'], 6000) }}@endif</div>
        </li>
    @endforeach
    @if (! $running && $hidden > 0)
        <li class="wf-step" x-show="!all">
            <span class="wf-step-icon is-count" aria-hidden="true">+{{ $hidden }}</span>
            <button type="button" class="wf-step-more-btn" x-on:click="all = true">{{ trans_choice('workspaces.steps.more', $hidden, ['count' => $hidden]) }}</button>
        </li>
    @endif
</ol>
