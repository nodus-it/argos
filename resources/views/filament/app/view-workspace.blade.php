@use('App\Enums\WorkspaceStatus')
{{-- Workspace "Fokus": header (task switcher, ship), the chat in a centred
     column and the context sidebar. The review mode widens the sidebar into
     the review panel (see resources/js/workspace/focus.js). --}}
<div class="ag-page" x-data="argosWorkspaceFocus()" x-on:keydown.window="onKey($event)"
     x-on:argos-task-url.window="if (new URL($event.detail.url).pathname !== location.pathname) history.replaceState(history.state, '', $event.detail.url)"
     :class="{ 'is-review': review, 'is-max': review && maximized, 'is-drawer-open': drawer }">
    {{-- Lets notifications.js skip signals for the workspace in front of you. --}}
    <script>window.ArgosCurrentWorkspace = @js($workspace->id);</script>

    {{-- Container state changes come from queued jobs; poll only while one runs. --}}
    @if ($transitional)
        <div wire:poll.3s="refreshStatus" class="hidden"></div>
    @endif

    <header class="ag-head">
        <span class="ag-ws-name" title="{{ $workspace->name }} · {{ $workspace->getOwnerRepo() }}">
            <span class="ag-ws-title">{{ $workspace->name }}</span>
            <span @class(['ag-dot', 'is-'.$workspace->status->value]) role="img" aria-label="{{ $workspace->status->label() }}" title="{{ $workspace->status->label() }}"></span>
        </span>
        <span class="ag-head-sep" aria-hidden="true">/</span>
        @livewire('workspace.task-switcher', ['workspaceId' => $workspace->id], key('switcher-'.$workspace->id))
        @if ($task !== null)
            {{-- Who the task belongs to, who works on it, what it refers to. --}}
            <div class="ag-task-meta">
                @if ($task->owner !== null)
                    <x-ag.avatar :user="$task->owner" class="ag-avatar-xs is-owner" title="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}" aria-label="{{ __('workspaces.switcher.owner', ['name' => $task->owner->name]) }}" />
                @endif
                @foreach ($task->collaborators as $collaborator)
                    @if ($canInvite)
                        {{-- Owners remove collaborators with a click on their picture. --}}
                        <button type="button" class="ag-avatar-btn" wire:click="mountAction('uninvite', { user: {{ $collaborator->id }} })"
                                title="{{ __('workspaces.task_actions.collaborator', ['name' => $collaborator->name]) }} · {{ __('workspaces.task_actions.uninvite') }}"
                                aria-label="{{ __('workspaces.task_actions.uninvite_heading', ['name' => $collaborator->name]) }}">
                            <x-ag.avatar :user="$collaborator" />
                        </button>
                    @else
                        <x-ag.avatar :user="$collaborator" title="{{ __('workspaces.task_actions.collaborator', ['name' => $collaborator->name]) }}" />
                    @endif
                @endforeach
                @if ($canInvite)
                    <button type="button" class="ag-icon-btn ag-icon-btn-xs" wire:click="mountAction('invite')" aria-label="{{ __('workspaces.task_actions.invite') }}" title="{{ __('workspaces.task_actions.invite') }}">
                        <x-ag.icon name="plus" :size="13" :stroke="2" />
                    </button>
                @endif
                @if ($task->external_ref !== null)
                    <a class="ag-ref-chip ag-hide-narrow" href="{{ $task->external_ref['url'] ?? '#' }}" target="_blank" rel="noopener" title="{{ $task->external_ref['title'] ?? '' }}">
                        <x-ag.icon name="link" :size="11" :stroke="2" /> {{ \App\Support\TaskRefs::ticketLabel($task) }}
                    </a>
                @endif
            </div>
        @endif
        <span class="ag-review-flag" x-show="review" x-cloak>
            <x-ag.icon name="eye" :size="15" :stroke="2" /> {{ __('workspaces.focus.review_mode') }}
        </span>
        <span class="ag-spacer"></span>
        <button type="button" class="ag-btn" x-show="review" x-cloak x-on:click="closeReview()">
            {{ __('workspaces.focus.close_review') }} <kbd class="ag-kbd">esc</kbd>
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
        <button type="button" class="ag-icon-btn ag-drawer-toggle" x-on:click="drawer = !drawer" :aria-expanded="drawer" aria-label="{{ __('workspaces.focus.context') }}">
            <x-ag.icon name="layout" :size="18" />
        </button>
    </header>

    {{-- Keyboard shortcuts ("?" or the ⋯ menu). --}}
    <div class="ag-keys-backdrop" x-data="{ show: false }" x-show="show" x-cloak
         x-on:argos-shortcuts.window="show = true" x-on:keydown.escape.window="show = false" x-on:click.self="show = false">
        <div class="ag-keys" role="dialog" aria-modal="true" aria-labelledby="ag-keys-title">
            <div class="ag-keys-head">
                <h2 id="ag-keys-title">{{ __('workspaces.shortcuts.title') }}</h2>
                <button type="button" class="ag-icon-btn ag-icon-btn-sm" x-on:click="show = false" aria-label="{{ __('workspaces.shortcuts.close') }}"><x-ag.icon name="x" :size="15" :stroke="2" /></button>
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
                        <div><dt>@foreach (explode(' ', $combo) as $k)<kbd class="ag-kbd">{{ $k }}</kbd>@endforeach</dt><dd>{{ __('workspaces.shortcuts.items.'.$item) }}</dd></div>
                    @endforeach
                </dl>
            @endforeach
        </div>
    </div>

    {{-- Maximised review: the chat folds into a strip. --}}
    <div class="ag-chat-strip" x-show="review && maximized" x-cloak>
        <button type="button" class="ag-icon-btn" x-on:click="maximized = false" aria-label="{{ __('workspaces.review.chat_strip') }}" title="{{ __('workspaces.review.chat_strip') }}">
            <x-ag.icon name="chevron-right" :size="16" :stroke="2" />
        </button>
        <button type="button" class="ag-icon-btn ag-strip-comments" x-on:click="maximized = false" x-show="commentCount > 0" aria-label="{{ __('workspaces.review.strip_comments') }}" title="{{ __('workspaces.review.strip_comments') }}">
            <x-ag.icon name="message" :size="17" />
            <span class="ag-strip-badge" x-text="commentCount"></span>
        </button>
        <span class="ag-strip-text">{{ __('workspaces.review.chat_strip') }}</span>
    </div>

    <div class="ag-center" x-show="!(review && maximized)">
        @if ($onboarding !== null && collect($onboarding)->contains(fn (array $s): bool => $s['required'] && ! $s['done']))
            <section class="ag-onboarding-card" aria-labelledby="ag-ob-title">
                <div class="ag-ob-head">
                    <h2 id="ag-ob-title" class="ag-ob-title">{{ __('workspaces.onboarding.title') }} · {{ collect($onboarding)->where('done', true)->count() }}/{{ count($onboarding) }}</h2>
                    <button type="button" class="ag-text-btn" x-on:click="$dispatch('workspace-focus', { target: 'setup' })">{{ __('workspaces.onboarding.all_steps') }}</button>
                </div>
                @include('livewire.workspace.partials.onboarding', ['steps' => array_values(array_filter($onboarding, fn (array $s): bool => $s['required'] && ! $s['done']))])
            </section>
        @endif
        @if ($workspace->status !== WorkspaceStatus::Running)
            <div @class(['ag-banner', 'is-error' => $workspace->status === WorkspaceStatus::Failed]) role="status">
                @if ($transitional) <span class="ag-spin"></span> @else <x-ag.icon name="alert" :size="16" /> @endif
                <span>{{ __('workspaces.states.'.$workspace->status->value) }}</span>
                @if ($workspace->status === WorkspaceStatus::Failed && $workspace->status_message)
                    <pre class="ag-banner-detail mono">{{ $workspace->status_message }}</pre>
                @endif
            </div>
        @endif

        @livewire('workspace.task-chat', ['workspaceId' => $workspace->id], key('chat-'.$workspace->id))
    </div>

    <div class="ag-drawer-backdrop" x-show="drawer" x-cloak x-on:click="drawer = false" aria-hidden="true"></div>
    <aside class="ag-context" aria-label="{{ __('workspaces.focus.context') }}">
        <button type="button" class="ag-icon-btn ag-drawer-close" x-on:click="drawer = false" aria-label="{{ __('workspaces.focus.close_context') }}" title="{{ __('workspaces.focus.close_context') }}"><x-ag.icon name="x" :size="18" :stroke="2" /></button>
        <div x-show="!review">
            @livewire('workspace.context-panel', ['workspaceId' => $workspace->id], key('context-'.$workspace->id))
        </div>
        <div x-show="review" x-cloak class="ag-review-host">
            @livewire('workspace.review-panel', ['workspaceId' => $workspace->id], key('review-'.$workspace->id))
        </div>
    </aside>

    <x-filament-actions::modals />
</div>
