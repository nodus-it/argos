@php
    /** @var \App\Models\Task $record */
    $record = $getRecord();
    $phase = $record->current_phase;
@endphp
@if ($phase)
    <x-argos.phase-chip :phase="$phase->value" :label="$phase->label()" />
@else
    <span class="chip">—</span>
@endif
