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

    <div class="fade-in ws-meta">
        <x-argos.meta-strip>
            <x-argos.meta-item label="{{ __('workspaces.fields.status') }}">
                <span class="badge {{ $statusBadge }}">{{ $workspace->status->label() }}</span>
            </x-argos.meta-item>
            <x-argos.meta-item label="{{ __('workspaces.fields.project') }}" :mono="true">{{ $workspace->repoProfile->name }}</x-argos.meta-item>
            <x-argos.meta-item label="{{ __('workspaces.fields.branch') }}" :mono="true">{{ $workspace->branch }}</x-argos.meta-item>
            <x-argos.meta-item label="{{ __('workspaces.fields.base_branch') }}" :mono="true">{{ $workspace->base_branch }}</x-argos.meta-item>
            @if ($workspace->pr_url)
                <x-argos.meta-item label="{{ __('workspaces.ship.pr') }}" :link="true">
                    <a href="{{ $workspace->pr_url }}" target="_blank" rel="noopener">#{{ $workspace->pr_number }}</a>
                    @if ($workspace->pr_state)
                        <span class="badge {{ $workspace->pr_state === 'merged' ? 'badge-success' : ($workspace->pr_state === 'open' ? 'badge-running' : 'badge-draft') }}">{{ __('workspaces.pr_state.'.$workspace->pr_state) }}</span>
                    @endif
                </x-argos.meta-item>
            @endif
            <x-argos.meta-item label="{{ __('workspaces.fields.members') }}">
                {{ $members->map(fn ($m) => $m->user->name.' ('.$m->role->label().')')->implode(', ') }}
            </x-argos.meta-item>
        </x-argos.meta-strip>
    </div>

    @if ($workspace->status !== WorkspaceStatus::Running)
        <div @class(['ws-alert', 'ws-alert-error' => $workspace->status === WorkspaceStatus::Failed, 'ws-alert-warn' => $workspace->status !== WorkspaceStatus::Failed]) role="status">
            @if ($transitional) <span class="ws-dot"></span> @else @svg('heroicon-o-information-circle') @endif
            <span>{{ __('workspaces.states.'.$workspace->status->value) }}</span>
            @if ($workspace->status === WorkspaceStatus::Failed && $workspace->status_message)
                <pre class="ws-error-detail mono">{{ $workspace->status_message }}</pre>
            @endif
        </div>
    @endif

    <div class="ws-layout" x-data="{ tab: 'code' }" x-on:workspace-tab.window="tab = $event.detail.tab">
        <section class="ws-pane ws-pane-chat card">
            @livewire('workspace.thread-chat', ['workspaceId' => $workspace->id], key('chat-'.$workspace->id))
        </section>

        <section class="ws-pane ws-pane-tools card">
            <div class="ws-tabs" role="tablist">
                <button type="button" role="tab" :class="{ 'on': tab === 'code' }" x-on:click="tab = 'code'">@svg('heroicon-o-code-bracket') {{ __('workspaces.tabs.code') }}</button>
                <button type="button" role="tab" :class="{ 'on': tab === 'preview' }" x-on:click="tab = 'preview'">@svg('heroicon-o-globe-alt') {{ __('workspaces.tabs.preview') }}</button>
                <button type="button" role="tab" :class="{ 'on': tab === 'review' }" x-on:click="tab = 'review'">@svg('heroicon-o-document-magnifying-glass') {{ __('workspaces.tabs.review') }}</button>
            </div>
            <div class="ws-tab-body" x-show="tab === 'code'">
                @include('filament.admin.resources.workspace.partials.code-tab', ['workspace' => $workspace])
            </div>
            <div class="ws-tab-body" x-show="tab === 'preview'" x-cloak>
                @include('filament.admin.resources.workspace.partials.preview-tab', ['workspace' => $workspace])
            </div>
            <div class="ws-tab-body" x-show="tab === 'review'" x-cloak>
                @livewire('workspace.review-panel', ['workspaceId' => $workspace->id], key('review-'.$workspace->id))
            </div>
        </section>
    </div>
</x-filament-panels::page>
