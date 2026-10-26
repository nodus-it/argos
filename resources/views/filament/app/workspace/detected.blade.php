{{-- What the guided creation read from the repository: one row per finding, with where it came from. --}}
<dl class="ag-detected">
    @foreach ($rows as $row)
        <div class="ag-detected-row">
            <dt>{{ $row['label'] }}</dt>
            <dd>
                <span @class(['ag-detected-value', 'is-none' => ! $row['found']])>{{ $row['value'] }}</span>
                @if ($row['reason'] !== '')
                    <span class="ag-detected-reason">{{ $row['reason'] }}</span>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
