@php
    /** Split: $row = ['left' => line|null, 'right' => line|null]; unified: $line. */
    $sign = fn (?array $l): string => match ($l['type'] ?? null) { 'add' => '+', 'del' => '−', default => ' ' };
@endphp
@if ($row !== null)
    <div class="wf-drow">
        @foreach (['left' => 'old_num', 'right' => 'new_num'] as $side => $numKey)
            @php $l = $row[$side]; @endphp
            <div @class(['wf-dcell', 'is-'.$side, 'is-add' => ($l['type'] ?? '') === 'add', 'is-del' => ($l['type'] ?? '') === 'del', 'is-empty' => $l === null])>
                <span class="wf-dnum">{{ $l[$numKey] ?? '' }}</span>
                <span class="wf-dtext">@if ($l !== null)<span class="wf-dsign">{{ $sign($l) }}</span>{{ $l['text'] }}@endif</span>
            </div>
        @endforeach
    </div>
@else
    <div @class(['wf-uline', 'is-add' => $line['type'] === 'add', 'is-del' => $line['type'] === 'del'])>
        <span class="wf-dnum">{{ $line['old_num'] ?? '' }}</span>
        <span class="wf-dnum">{{ $line['new_num'] ?? '' }}</span>
        <span class="wf-dtext"><span class="wf-dsign">{{ $sign($line) }}</span>{{ $line['text'] }}</span>
    </div>
@endif
