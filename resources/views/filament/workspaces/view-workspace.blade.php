@use('App\Enums\WorkspaceStatus')
<x-filament-panels::page>
    {{-- Lets notifications.js skip signals for the workspace in front of you. --}}
    <script>window.ArgosCurrentWorkspace = @js($workspace->id);</script>

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
    <div class="ws-layout" x-data="argosWorkspaceLayout()" x-on:workspace-focus.window="show($event.detail.target)"
         :class="{ 'is-resizing': resizing }" :style="style()">
        <section class="ws-pane ws-pane-chat card" id="ws-chat-pane">
            @livewire('workspace.thread-chat', ['workspaceId' => $workspace->id], key('chat-'.$workspace->id))
        </section>

        <div class="ws-gutter" role="separator" tabindex="0" aria-orientation="vertical"
             aria-label="{{ __('workspaces.tabs.resize') }}" aria-controls="ws-chat-pane"
             aria-valuemin="25" aria-valuemax="70" :aria-valuenow="Math.round(split)"
             x-on:pointerdown.prevent="startResize($event)"
             x-on:keydown.left.prevent="nudge(-2)" x-on:keydown.right.prevent="nudge(2)"
             x-on:keydown.home.prevent="nudge(-100)" x-on:keydown.end.prevent="nudge(100)"></div>

        <section class="ws-pane ws-pane-tools card">
            {{-- Workbench: editor and preview open in their own tabs; the
                 review (diff, line comments, gates) stays here. --}}
            <div class="ws-workbench-bar" :class="{ 'is-highlighted': highlight === 'preview' }">
                @include('filament.workspaces.partials.code-tool')
                @livewire('workspace.preview-panel', ['workspaceId' => $workspace->id], key('preview-'.$workspace->id))
            </div>
            <div class="ws-review-wrap" x-ref="review">
                @livewire('workspace.review-panel', ['workspaceId' => $workspace->id], key('review-'.$workspace->id))
            </div>
        </section>
    </div>
</x-filament-panels::page>
