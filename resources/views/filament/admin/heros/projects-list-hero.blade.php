{{-- Workspaces (admin) list page hero. Embedded via ListWorkspaces::getHeader(). --}}
@php
    use App\Enums\WorkspaceStatus;
    use App\Models\Workspace;

    $totalProjects = Workspace::count();
    $runningWorkspaces = Workspace::where('status', WorkspaceStatus::Running)->count();

    $chips = [
        ['label' => __('widgets.hero.chips.projects'), 'value' => $totalProjects, 'type' => 'ok'],
        ['label' => __('widgets.hero.chips.running_workspaces'), 'value' => $runningWorkspaces, 'type' => 'run'],
    ];
@endphp

{{-- Hidden poll element so Livewire re-renders getHeader() with fresh counts --}}
<div wire:poll.5s class="hidden"></div>

<x-argos.page-hero
    icon="folder"
    :title="__('projects.navigation_label')"
    :sub="__('widgets.hero.projects_sub')"
    :chips="$chips"
>
    <a href="{{ \App\Support\DocLink::url('projects') }}" target="_blank" rel="noopener"
       class="btn btn-ghost" style="white-space:nowrap;">
        {{ __('navigation.pages.documentation') }}
    </a>
    <a href="{{ \App\Filament\Admin\Resources\WorkspaceResource::getUrl('create') }}"
       class="btn btn-primary" style="white-space:nowrap;">
        {{ __('widgets.hero.new_project') }}
    </a>
</x-argos.page-hero>
