@php
    use App\Filament\Admin\Resources\TaskResource;

    /** @var \Filament\Resources\RelationManagers\RelationManager $this */
    $tabs = [
        'aktuell' => __('tasks.tabs.current'),
        'wartend' => __('tasks.tabs.waiting'),
        'abgeschlossen' => __('tasks.tabs.completed'),
        'alle' => __('tasks.tabs.all'),
    ];
    $createUrl = TaskResource::getUrl('create', [
        'repo_profile_id' => $this->getOwnerRecord()->getKey(),
    ]);
@endphp

{{-- Single-row relation toolbar (matches related.png): status segment on the
     left, search + "Neuer Task" on the right. The status segment drives the
     table's $activeTab (the query scoping still comes from getTabs()); the
     search input binds to the table's $tableSearch. The default filter pill
     and table search row are hidden via CSS (see app.css, .fi-rel-toolbar). --}}
<div class="fi-rel-toolbar">
    <div class="seg">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                wire:click="$set('activeTab', '{{ $key }}')"
                @class(['on' => $this->activeTab === $key])
            >{{ $label }}</button>
        @endforeach
    </div>

    <div class="fi-rel-toolbar-end">
        <label class="search-box">
            @svg('heroicon-o-magnifying-glass')
            <input
                type="search"
                wire:model.live.debounce.400ms="tableSearch"
                placeholder="{{ __('tasks.search_placeholder') }}"
            />
        </label>

        <a href="{{ $createUrl }}" class="btn btn-primary btn-sm" wire:navigate>
            @svg('heroicon-o-plus')
            {{ __('projects.actions.new_task') }}
        </a>
    </div>
</div>
