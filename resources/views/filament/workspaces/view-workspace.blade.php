@use('App\Enums\WorkspaceStatus')
<x-filament-panels::page>
    {{-- Lets notifications.js skip signals for the workspace in front of you. --}}
    <script>window.ArgosCurrentWorkspace = @js($workspace->id);</script>
    @php
        /** @var \App\Models\Workspace $workspace */
        $statusBadge = \App\Support\BadgeClass::for($workspace->status->color());
    @endphp

    {{-- Container state changes come from queued jobs; poll only while one runs. --}}
    @if ($transitional)
        <div wire:poll.3s="refreshStatus" class="hidden"></div>
    @endif

    @if ($workspace->status !== WorkspaceStatus::Running)
        <div @class(['ws-alert', 'ws-alert-error' => $workspace->status === WorkspaceStatus::Failed, 'ws-alert-warn' => $workspace->status !== WorkspaceStatus::Failed]) role="status">
            @if ($transitional) <span class="ws-dot"></span> @else @svg('heroicon-o-information-circle') @endif
            <span>{{ __('workspaces.states.'.$workspace->status->value) }}</span>
            @if ($workspace->status === WorkspaceStatus::Failed && $workspace->status_message)
                <pre class="ws-error-detail mono">{{ $workspace->status_message }}</pre>
            @endif
        </div>
    @endif

    {{-- Fills the viewport below the header; the chat feed scrolls inside.
         The gutter between the panes sets the split (remembered per browser). --}}
    <div class="ws-layout" x-data="window.argosWorkspaceLayout ? argosWorkspaceLayout() : { highlight: null, resizing: false, style() { return '' }, startResize() {}, nudge() {}, show() {} }" x-on:workspace-tab.window="show($event.detail.tab)"
         :class="{ 'is-resizing': resizing }" :style="style()">
        <section class="ws-pane ws-pane-chat card">
            @livewire('workspace.thread-chat', ['workspaceId' => $workspace->id], key('chat-'.$workspace->id))
        </section>

        <div class="ws-gutter" role="separator" aria-orientation="vertical" aria-label="{{ __('workspaces.tabs.resize') }}"
             tabindex="0" x-on:pointerdown.prevent="startResize($event)" x-on:keydown.left.prevent="nudge(-2)" x-on:keydown.right.prevent="nudge(2)"></div>

        <section class="ws-pane ws-pane-tools card">
            {{-- Workbench: editor + preview open in their own tabs; the review
                 (diff, line comments, gates) stays here. --}}
            <div class="ws-workbench-bar" :class="{ 'is-highlighted': highlight === 'preview' }">
                @include('filament.admin.resources.workspace.partials.code-tab', ['workspace' => $workspace])
                <span class="ws-workbench-sep" aria-hidden="true"></span>
                @include('filament.admin.resources.workspace.partials.preview-tab', ['workspace' => $workspace])
            </div>
            <div class="ws-tab-body" x-ref="review">
                @livewire('workspace.review-panel', ['workspaceId' => $workspace->id], key('review-'.$workspace->id))
            </div>
        </section>
    </div>
</x-filament-panels::page>
