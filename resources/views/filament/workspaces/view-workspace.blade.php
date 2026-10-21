@use('App\Enums\WorkspaceStatus')
{{-- Workspace "Fokus": header (task switcher, ship), the chat in a centred
     column and the context sidebar. The review mode widens the sidebar into
     the review panel (see resources/js/workspace/focus.js). --}}
<div class="wf-page" x-data="argosWorkspaceFocus()" x-on:keydown.window="onKey($event)"
     :class="{ 'is-review': review, 'is-max': review && maximized, 'is-drawer-open': drawer }">
    {{-- Lets notifications.js skip signals for the workspace in front of you. --}}
    <script>window.ArgosCurrentWorkspace = @js($workspace->id);</script>

    {{-- Container state changes come from queued jobs; poll only while one runs. --}}
    @if ($transitional)
        <div wire:poll.3s="refreshStatus" class="hidden"></div>
    @endif

    <header class="wf-head">
        <span class="wf-ws-name" title="{{ $workspace->name }} · {{ $workspace->getOwnerRepo() }}">
            <span class="wf-ws-title">{{ $workspace->name }}</span>
            <span @class(['wf-dot', 'is-'.$workspace->status->value]) role="img" aria-label="{{ $workspace->status->label() }}" title="{{ $workspace->status->label() }}"></span>
        </span>
        <span class="wf-head-sep" aria-hidden="true">/</span>
        @livewire('workspace.task-switcher', ['workspaceId' => $workspace->id], key('switcher-'.$workspace->id))
        @if ($task !== null)
            {{-- Who the task belongs to, who works on it, what it refers to. --}}
            <div class="wf-task-meta wf-hide-narrow">
                @if ($task->owner !== null)
                    <span class="wf-avatar-xs is-owner" title="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}" aria-label="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}">{{ \App\Support\Initials::of($task->owner->name) }}</span>
                @endif
                @foreach ($task->collaborators as $collaborator)
                    <span class="wf-avatar-xs" title="{{ __('workspaces.task_actions.collaborator', ['name' => $collaborator->name]) }}">
                        {{ \App\Support\Initials::of($collaborator->name) }}
                    </span>
                @endforeach
                @if ($canInvite)
                    <button type="button" class="wf-icon-btn wf-icon-btn-xs" wire:click="mountAction('invite')" aria-label="{{ __('workspaces.task_actions.invite') }}" title="{{ __('workspaces.task_actions.invite') }}">
                        <x-wf.icon name="plus" :size="13" :stroke="2" />
                    </button>
                @endif
                @if ($task->external_ref !== null)
                    <a class="wf-ref-chip" href="{{ $task->external_ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $task->external_ref['title'] ?? '' }}">
                        <x-wf.icon name="link" :size="11" :stroke="2" /> {{ \App\Support\TaskRefs::ticketLabel($task) }}
                    </a>
                @endif
                @if ($task->pr_url !== null)
                    <a class="wf-ref-chip is-pr is-{{ $task->pr_state ?? 'open' }}" href="{{ $task->pr_url }}" target="_blank" rel="noopener">
                        <x-wf.icon name="git-pr" :size="11" :stroke="2" /> #{{ $task->pr_number }} · {{ __('workspaces.pr_state.'.($task->pr_state ?? 'open')) }}
                    </a>
                @endif
            </div>
        @endif
        <span class="wf-review-flag" x-show="review" x-cloak>
            <x-wf.icon name="eye" :size="15" :stroke="2" /> {{ __('workspaces.focus.review_mode') }}
        </span>
        <span class="wf-spacer"></span>
        <button type="button" class="wf-btn" x-show="review" x-cloak x-on:click="closeReview()">
            {{ __('workspaces.focus.close_review') }} <kbd class="wf-kbd">esc</kbd>
        </button>
        @if ($canCreate)
            <button type="button" class="wf-btn wf-hide-narrow" x-show="!review" x-on:click="$dispatch('task-new')">
                <x-wf.icon name="plus" :size="16" :stroke="2" /> {{ __('workspaces.task.new') }}
            </button>
        @endif
        @if ($this->startAction->isVisible()) {{ $this->startAction }} @endif
        @if ($this->shipAction->isVisible()) {{ $this->shipAction }} @endif
        <x-filament-actions::group
            :actions="[
                \Filament\Actions\ActionGroup::make([$this->inviteAction, $this->transferAction, $this->linkTicketAction, $this->createTicketAction, $this->followUpAction, $this->gatesAction, $this->rebaseAction])->dropdown(false),
                \Filament\Actions\ActionGroup::make([$this->cancelTaskAction, $this->deleteTaskAction])->dropdown(false),
                \Filament\Actions\ActionGroup::make([$this->settingsAction, $this->stopAction, $this->deleteAction])->dropdown(false),
            ]"
            icon="heroicon-o-ellipsis-horizontal"
            :label="__('workspaces.focus.more')"
            color="gray"
            dropdown-placement="bottom-end"
        />
        <button type="button" class="wf-icon-btn wf-drawer-toggle" x-on:click="drawer = !drawer" :aria-expanded="drawer" aria-label="{{ __('workspaces.focus.context') }}">
            <x-wf.icon name="layout" :size="18" />
        </button>
    </header>

    {{-- Maximised review: the chat folds into a strip. --}}
    <div class="wf-chat-strip" x-show="review && maximized" x-cloak>
        <button type="button" class="wf-icon-btn" x-on:click="maximized = false" aria-label="{{ __('workspaces.review.chat_strip') }}" title="{{ __('workspaces.review.chat_strip') }}">
            <x-wf.icon name="chevron-right" :size="16" :stroke="2" />
        </button>
        <button type="button" class="wf-icon-btn wf-strip-comments" x-on:click="maximized = false" x-show="commentCount > 0" aria-label="{{ __('workspaces.review.send_review') }}">
            <x-wf.icon name="message" :size="17" />
            <span class="wf-strip-badge" x-text="commentCount"></span>
        </button>
        <span class="wf-strip-text">{{ __('workspaces.review.chat_strip') }}</span>
    </div>

    <div class="wf-center" x-show="!(review && maximized)">
        @if ($workspace->status !== WorkspaceStatus::Running)
            <div @class(['wf-banner', 'is-error' => $workspace->status === WorkspaceStatus::Failed]) role="status">
                @if ($transitional) <span class="wf-spin"></span> @else <x-wf.icon name="alert" :size="16" /> @endif
                <span>{{ __('workspaces.states.'.$workspace->status->value) }}</span>
                @if ($workspace->status === WorkspaceStatus::Failed && $workspace->status_message)
                    <pre class="wf-banner-detail mono">{{ $workspace->status_message }}</pre>
                @endif
            </div>
        @endif

        @livewire('workspace.task-chat', ['workspaceId' => $workspace->id], key('chat-'.$workspace->id))
    </div>

    <aside class="wf-context" aria-label="{{ __('workspaces.focus.context') }}">
        <div x-show="!review">
            @livewire('workspace.context-panel', ['workspaceId' => $workspace->id], key('context-'.$workspace->id))
        </div>
        <div x-show="review" x-cloak class="wf-review-host">
            @livewire('workspace.review-panel', ['workspaceId' => $workspace->id], key('review-'.$workspace->id))
        </div>
    </aside>

    <x-filament-actions::modals />
</div>
