{{-- Workspaces in the icon rail: abbreviation buttons (tooltip = name)
     with a count of tasks waiting for the signed-in user. --}}
<div class="ag-rail-ws" wire:poll.30s
     x-data="{ timer: null }"
     x-on:argos-workspace-signal.window="clearTimeout(timer); timer = setTimeout(() => $wire.$refresh(), 400)">
    @foreach ($rows as $row)
        @php
            $ws = $row['workspace'];
            $isActive = $ws->id === $activeId;
            $label = $row['waiting'] > 0
                ? __('workspaces.rail.workspace_waiting', ['name' => $ws->name, 'count' => $row['waiting']])
                : $ws->name;
        @endphp
        <a href="{{ $ws->url() }}" wire:key="rail-ws-{{ $ws->id }}" data-workspace="{{ $ws->id }}"
           @class(['ag-rail-btn', 'ag-rail-abbr', 'is-active' => $isActive])
           aria-label="{{ $label }}" title="{{ $ws->name }}" @if ($isActive) aria-current="page" @endif>
            <span aria-hidden="true">{{ $row['short'] }}</span>
            @if ($row['waiting'] > 0)
                <span class="ag-rail-count" aria-hidden="true">{{ $row['waiting'] > 9 ? '9+' : $row['waiting'] }}</span>
            @endif
        </a>
    @endforeach
</div>
