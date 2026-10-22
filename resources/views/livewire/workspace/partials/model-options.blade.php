@php
    /** @var list<array{value: string, label: string, description: string, group: string}> $models */
    /** @var string|null $selected */
    $groups = collect($models)->groupBy('group');
@endphp
{{-- Model choices like Claude Code's /model: always-newest aliases, fixed versions, and any other id by hand ("__custom"). --}}
@foreach (['latest', 'pinned', 'other'] as $group)
    @if ($groups->has($group))
        <optgroup label="{{ __('workspaces.models.group_'.$group) }}">
            @foreach ($groups[$group] as $model)
                <option value="{{ $model['value'] }}" title="{{ $model['description'] }}" @selected($selected === $model['value'])>{{ $model['label'] }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
<option value="__custom">{{ __('workspaces.models.custom') }}</option>
