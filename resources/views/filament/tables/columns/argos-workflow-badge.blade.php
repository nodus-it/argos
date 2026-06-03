@php
    /** @var \App\Models\Task $record */
    $record = $getRecord();
@endphp
<x-argos.badge :status="$record->displayBadgeStatus()" :label="$record->displayStatusLabel()" />
