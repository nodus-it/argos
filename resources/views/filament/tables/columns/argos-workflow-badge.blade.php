@php
    /** @var \App\Models\Task $record */
    $record = $getRecord();
@endphp
<x-argos.badge :status="$record->presenter()->badgeStatus()" :label="$record->presenter()->statusLabel()" />
