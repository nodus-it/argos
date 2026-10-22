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
            <div class="wf-task-meta">
                @if ($task->owner !== null)
                    <x-wf.avatar :user="$task->owner" class="wf-avatar-xs is-owner" title="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}" aria-label="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}" />
                @endif
                @foreach ($task->collaborators as $collaborator)
                    @if ($canInvite)
                        {{-- Owners remove collaborators with a click on their picture. --}}
                        <button type="button" class="wf-avatar-btn" wire:click="mountAction('uninvite', { user: {{ $collaborator->id }} })"
                                title="{{ __('workspaces.task_actions.collaborator', ['name' => $collaborator->name]) }} · {{ __('workspaces.task_actions.uninvite') }}"
                                aria-label="{{ __('workspaces.task_actions.uninvite_heading', ['name' => $collaborator->name]) }}">
                            <x-wf.avatar :user="$collaborator" />
                        </button>
                    @else
                        <x-wf.avatar :user="$collaborator" title="{{ __('workspaces.task_actions.collaborator', ['name' => $collaborator->name]) }}" />
                    @endif
                @endforeach
                @if ($canInvite)
                    <button type="button" class="wf-icon-btn wf-icon-btn-xs" wire:click="mountAction('invite')" aria-label="{{ __('workspaces.task_actions.invite') }}" title="{{ __('workspaces.task_actions.invite') }}">
                        <x-wf.icon name="plus" :size="13" :stroke="2" />
                    </button>
                @endif
                @if ($task->external_ref !== null)
                    <a class="wf-ref-chip wf-hide-narrow" href="{{ $task->external_ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $task->external_ref['title'] ?? '' }}">
                        <x-wf.icon name="link" :size="11" :stroke="2" /> {{ \App\Support\TaskRefs::ticketLabel($task) }}
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
        @if ($this->startAction->isVisible()) {{ $this->startAction }} @endif
        <x-filament-actions::group
            :actions="[
                \Filament\Actions\ActionGroup::make([$this->transferAction, $this->linkTicketAction, $this->createTicketAction, $this->followUpAction, $this->rebaseAction])->dropdown(false),
                \Filament\Actions\ActionGroup::make([$this->cancelTaskAction, $this->deleteTaskAction])->dropdown(false),
                \Filament\Actions\ActionGroup::make([$this->settingsAction, $this->shortcutsAction, $this->stopAction, $this->deleteAction])->dropdown(false),
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

    {{-- Keyboard shortcuts ("?" or the ⋯ menu). --}}
    <div class="wf-keys-backdrop" x-data="{ show: false }" x-show="show" x-cloak
         x-on:argos-shortcuts.window="show = true" x-on:keydown.escape.window="show = false" x-on:click.self="show = false">
        <div class="wf-keys" role="dialog" aria-modal="true" aria-labelledby="wf-keys-title">
            <div class="wf-keys-head">
                <h2 id="wf-keys-title">{{ __('workspaces.shortcuts.title') }}</h2>
                <button type="button" class="wf-icon-btn wf-icon-btn-sm" x-on:click="show = false" aria-label="{{ __('workspaces.shortcuts.close') }}"><x-wf.icon name="x" :size="15" :stroke="2" /></button>
            </div>
            @php
                $shortcutGroups = [
                    'navigate' => [['⌘/Ctrl J', 'switcher'], ['?', 'help'], ['Esc', 'escape']],
                    'compose' => [['Enter', 'send'], ['Shift Enter', 'newline'], ['/', 'command'], ['@', 'mention'], ['↑ ↓ Tab', 'pick']],
                    'review' => [['+', 'comment'], ['Enter', 'save_comment']],
                ];
            @endphp
            @foreach ($shortcutGroups as $group => $rows)
                <h3>{{ __('workspaces.shortcuts.groups.'.$group) }}</h3>
                <dl>
                    @foreach ($rows as [$combo, $item])
                        <div><dt>@foreach (explode(' ', $combo) as $k)<kbd class="wf-kbd">{{ $k }}</kbd>@endforeach</dt><dd>{{ __('workspaces.shortcuts.items.'.$item) }}</dd></div>
                    @endforeach
                </dl>
            @endforeach
        </div>
    </div>

    {{-- Maximised review: the chat folds into a strip. --}}
    <div class="wf-chat-strip" x-show="review && maximized" x-cloak>
        <button type="button" class="wf-icon-btn" x-on:click="maximized = false" aria-label="{{ __('workspaces.review.chat_strip') }}" title="{{ __('workspaces.review.chat_strip') }}">
            <x-wf.icon name="chevron-right" :size="16" :stroke="2" />
        </button>
        <button type="button" class="wf-icon-btn wf-strip-comments" x-on:click="maximized = false" x-show="commentCount > 0" aria-label="{{ __('workspaces.review.strip_comments') }}" title="{{ __('workspaces.review.strip_comments') }}">
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
