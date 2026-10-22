@php
    /** @var list<array{id: int, state: string, verb: string, target: string, add: int|null, del: int|null, detail: string, input: mixed, output: string, at: string|null}> $rows */
    $limit = 3;
    $hidden = max(0, count($rows) - $limit);
    // Finished turns show the first steps, a running turn the latest ones.
    $shown = fn (int $i): bool => $hidden === 0 || ($running ? $i >= $hidden : $i < $limit);
@endphp
{{-- Tool calls as a compact timeline: state, verb, target, +/− or result. --}}
<ol class="ag-steps" x-data="{ all: false }" wire:key="steps-{{ $rows[0]['id'] }}">
    @if ($running && $hidden > 0)
        <li class="ag-step" x-show="!all">
            <span class="ag-step-icon is-count" aria-hidden="true">+{{ $hidden }}</span>
            <button type="button" class="ag-step-more-btn" x-on:click="all = true">{{ trans_choice('workspaces.steps.more', $hidden, ['count' => $hidden]) }}</button>
        </li>
    @endif
    @foreach ($rows as $i => $row)
        <li class="ag-step is-{{ $row['state'] }}" @unless ($shown($i)) x-show="all" x-cloak @endunless x-data="{ open: false }">
            <button type="button" class="ag-step-row" x-on:click="open = !open" :aria-expanded="open" title="{{ __('workspaces.steps.details') }}">
                <span class="ag-step-icon" aria-hidden="true">
                    @switch ($row['state'])
                        @case ('ok') <x-ag.icon name="check" :size="12" :stroke="2.6" /> @break
                        @case ('err') <x-ag.icon name="x" :size="12" :stroke="2.6" /> @break
                        @case ('run') <span class="ag-step-pulse"></span> @break
                        @default <span class="ag-step-dot"></span>
                    @endswitch
                </span>
                <span class="ag-step-verb">{{ $row['verb'] }}</span>
                @if ($row['target'] !== '')<span class="ag-step-target mono">{{ $row['target'] }}</span>@endif
                @if ($row['add'] !== null && $row['state'] !== 'run')
                    <span class="ag-step-stat mono">@if ($row['add'] > 0)<span class="ag-add">+{{ $row['add'] }}</span>@endif @if ($row['del'] > 0)<span class="ag-del">−{{ $row['del'] }}</span>@endif</span>
                @endif
                @if ($row['detail'] !== '')<span class="ag-step-detail">{{ $row['detail'] }}</span>@endif
                @if (! empty($row['at']))<time class="ag-step-time mono">{{ $row['at'] }}</time>@endif
            </button>
            <div class="ag-step-body mono" x-show="open" x-cloak>{{ \App\Support\TaskEventFormatter::json($row['input']) }}@if ($row['output'] !== '')

────
{{ \Illuminate\Support\Str::limit($row['output'], 6000) }}@endif</div>
        </li>
    @endforeach
    @if (! $running && $hidden > 0)
        <li class="ag-step" x-show="!all">
            <span class="ag-step-icon is-count" aria-hidden="true">+{{ $hidden }}</span>
            <button type="button" class="ag-step-more-btn" x-on:click="all = true">{{ trans_choice('workspaces.steps.more', $hidden, ['count' => $hidden]) }}</button>
        </li>
    @endif
</ol>
