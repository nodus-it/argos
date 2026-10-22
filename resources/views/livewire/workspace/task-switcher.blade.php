@php
    use App\Services\Workspace\TaskOverview;

    /** @var array<string, mixed> $overview */
    $groups = collect(TaskOverview::GROUPS)->mapWithKeys(fn (string $g): array => [$g => __('workspaces.switcher.'.$g)])->all();
    $doneLimit = 3;
    $empty = collect(TaskOverview::GROUPS)->every(fn (string $g): bool => $overview[$g] === []);
@endphp
{{-- Task switcher: button + popover (dialog with a listbox), ⌘J/Ctrl+J,
     arrows + Enter, Esc closes and returns focus. Live: re-renders on any
     task change in this workspace. --}}
<div class="ag-switcher" x-data="argosTaskSwitcher()"
     x-on:argos-tasks-changed.window="$wire.$refresh()"
     x-on:argos-switcher-toggle.window="toggle()"
     x-on:keydown.escape.prevent.stop="close()">
    <button type="button" class="ag-switch-btn" x-ref="button" data-testid="task-switcher" x-on:click="toggle()"
            aria-haspopup="dialog" :aria-expanded="open" aria-controls="ag-switcher-pop">
        <span @class(['ag-dot', 'is-'.($currentGroup ?? 'done')]) aria-hidden="true"></span>
        <span class="ag-switch-title">{{ $current?->title ?? __('workspaces.task.new') }}</span>
        <span class="ag-switch-count">{{ trans_choice('workspaces.switcher.count', $count, ['count' => $count]) }}</span>
        <kbd class="ag-kbd ag-hide-narrow" x-text="isMac ? '⌘J' : 'Ctrl J'">⌘J</kbd>
        <x-ag.icon name="chevron-down" :size="16" :stroke="2" class="ag-muted" />
    </button>
    @if ($canCreate)
        {{-- New task, joined to the switcher. --}}
        <button type="button" class="ag-switch-add" x-on:click="close(); $dispatch('task-new')"
                title="{{ __('workspaces.task.new') }}" aria-label="{{ __('workspaces.task.new') }}" data-testid="switcher-new-task">
            <x-ag.icon name="plus" :size="16" :stroke="2" />
        </button>
    @endif

    @if ($waitingElsewhere !== [])
        @php $first = $waitingElsewhere[0]['task']; @endphp
        <button type="button" class="ag-wait-chip" wire:click="select('{{ $first->id }}')" x-on:click="$dispatch('scroll-to-approval')"
                title="{{ $first->title }} · {{ $waitingElsewhere[0]['text'] }}">
            <span class="ag-dot is-waiting" aria-hidden="true"></span>
            {{ trans_choice('workspaces.switcher.waiting_chip', count($waitingElsewhere), ['count' => count($waitingElsewhere)]) }}
        </button>
    @endif

    <div id="ag-switcher-pop" class="ag-pop" role="dialog" aria-label="{{ __('workspaces.switcher.title') }}"
         x-show="open" x-cloak x-transition.opacity.duration.120ms
         x-on:click.outside="close(false)" x-on:keydown="onKey($event)">
        <div class="ag-pop-search">
            <x-ag.icon name="search" :size="16" :stroke="2" class="ag-muted" />
            <label class="sr-only" for="ag-task-search">{{ __('workspaces.switcher.search') }}</label>
            <input id="ag-task-search" type="text" x-ref="search" x-model="query" autocomplete="off"
                   placeholder="{{ __('workspaces.switcher.search') }} …" role="combobox" aria-controls="ag-task-list" :aria-activedescendant="activeId()">
            <kbd class="ag-kbd-plain mono">esc</kbd>
        </div>

        <div class="ag-pop-filter" role="group" aria-label="{{ __('workspaces.switcher.title') }}">
            <button type="button" @class(['ag-seg', 'is-on' => $mine]) wire:click="$set('mine', true)" aria-pressed="{{ $mine ? 'true' : 'false' }}">{{ __('workspaces.switcher.filter_mine') }}</button>
            <button type="button" @class(['ag-seg', 'is-on' => ! $mine]) wire:click="$set('mine', false)" aria-pressed="{{ $mine ? 'false' : 'true' }}">{{ __('workspaces.switcher.filter_all') }}</button>
        </div>

        <div class="ag-pop-list" id="ag-task-list" role="listbox" aria-label="{{ __('workspaces.switcher.title') }}" x-ref="list">
            @foreach ($groups as $group => $label)
                @php $rows = $overview[$group]; @endphp
                @continue($rows === [])
                <div class="ag-pop-group is-{{ $group }}" x-show="groupVisible(@js($group))">{{ mb_strtoupper($label) }} · {{ count($rows) }}</div>
                @foreach ($rows as $i => $row)
                    @php $t = $row['task']; $isCurrent = $t->id === $current?->id; @endphp
                    <div @class(['ag-pop-item', 'is-current' => $isCurrent, 'is-compact' => $group === TaskOverview::DONE])
                         id="ag-t-{{ $t->id }}" role="option" data-task="{{ $t->id }}" data-group="{{ $group }}"
                         data-title="{{ mb_strtolower($t->title) }}" @if ($group === TaskOverview::DONE && $i >= $doneLimit) data-more @endif
                         :aria-selected="activeId() === 'ag-t-{{ $t->id }}'" @if ($isCurrent) aria-current="true" @endif
                         x-show="visible($el)" wire:key="sw-{{ $t->id }}"
                         x-on:click="choose(@js($t->id))" x-on:mousemove="hover($el)">
                        <span @class(['ag-dot', 'is-'.$group]) aria-hidden="true"></span>
                        @if ($renamingId === $t->id)
                            <form wire:submit="rename" class="ag-pop-rename" x-on:click.stop>
                                <input type="text" class="ag-input" wire:model="renameTitle" maxlength="120" x-init="$nextTick(() => $el.focus())" x-on:keydown.stop>
                            </form>
                        @else
                            <span class="ag-pop-text">
                                <span class="ag-pop-title">
                                    @if ($t->auto_state)<x-ag.icon name="zap" :size="12" :stroke="2" class="ag-accent" label="{{ __('workspaces.auto.label') }}" />@endif
                                    {{ $t->title }}
                                </span>
                                @if ($group !== TaskOverview::DONE || $isCurrent)
                                    <span class="ag-pop-sub">{{ $row['text'] }}</span>
                                @endif
                                @if ($t->external_ref !== null || $t->pr_number !== null)
                                    <span class="ag-pop-refs">
                                        @if ($t->external_ref !== null)
                                            <span class="ag-ref-chip" title="{{ $t->external_ref['title'] ?? '' }}"><x-ag.icon name="link" :size="11" :stroke="2" /> {{ \App\Support\TaskRefs::ticketLabel($t) }}</span>
                                        @endif
                                        @if ($t->pr_number !== null)
                                            <span class="ag-ref-chip is-pr is-{{ $t->pr_state ?? 'open' }}"><x-ag.icon name="git-pr" :size="11" :stroke="2" /> #{{ $t->pr_number }}</span>
                                        @endif
                                    </span>
                                @endif
                            </span>
                        @endif
                        @if ($t->owner !== null && $t->owner_user_id !== $user->id)
                            <x-ag.avatar :user="$t->owner" title="{{ __('workspaces.switcher.owner', ['name' => $t->owner->name]) }}" aria-label="{{ __('workspaces.switcher.owner', ['name' => $t->owner->name]) }}" />
                        @endif
                        @if (($unread[$t->id] ?? 0) > 0 && ! $isCurrent)
                            <span class="ag-unread" title="{{ __('workspaces.switcher.unread') }}">{{ $unread[$t->id] }}</span>
                        @endif
                        <span class="ag-pop-time">{{ $t->updated_at?->diffForHumans(short: true) }}</span>
                        @php $mayRename = $user->can('update', $t); $mayArchive = $user->can('archive', $t); @endphp
                        @if ($mayRename || $mayArchive)
                            <span class="ag-pop-actions" x-on:click.stop>
                                @if ($mayRename)
                                    <button type="button" class="ag-icon-btn ag-icon-btn-xs" wire:click="startRename('{{ $t->id }}')" title="{{ __('workspaces.task.rename') }}" aria-label="{{ __('workspaces.task.rename') }}">@svg('heroicon-o-pencil')</button>
                                @endif
                                @if ($mayArchive)
                                    <button type="button" class="ag-icon-btn ag-icon-btn-xs" wire:click="archive('{{ $t->id }}')" wire:confirm="{{ __('workspaces.task.archive_confirm', ['title' => $t->title]) }}" title="{{ __('workspaces.task.archive') }}" aria-label="{{ __('workspaces.task.archive') }}">@svg('heroicon-o-archive-box')</button>
                                @endif
                            </span>
                        @endif
                    </div>
                @endforeach
                @if ($group === TaskOverview::DONE && count($rows) > $doneLimit)
                    <button type="button" class="ag-pop-more" x-show="!showMore && query === ''" x-on:click="showMore = true">
                        {{ __('workspaces.switcher.show_more', ['count' => count($rows) - $doneLimit]) }}
                    </button>
                @endif
            @endforeach

            @if ($empty)
                <p class="ag-pop-empty">
                    {{ __('workspaces.task.none_short') }}
                    @if ($mine && $others > 0)
                        <button type="button" class="ag-text-btn" wire:click="$set('mine', false)">{{ trans_choice('workspaces.switcher.show_others', $others, ['count' => $others]) }}</button>
                    @endif
                </p>
            @endif

            @if ($showArchived)
                <div class="ag-pop-group">{{ mb_strtoupper(__('workspaces.switcher.archive')) }} · {{ $archived->count() }}</div>
                @foreach ($archived as $t)
                    <div class="ag-pop-item is-compact is-archived" wire:key="ar-{{ $t->id }}" data-title="{{ mb_strtolower($t->title) }}" x-show="visible($el)">
                        <span class="ag-dot is-done" aria-hidden="true"></span>
                        <button type="button" class="ag-pop-text ag-pop-link" wire:click="select('{{ $t->id }}')" x-on:click="close()">{{ $t->title }}</button>
                        @if ($user->can('archive', $t))
                            <button type="button" class="ag-text-btn" wire:click="unarchive('{{ $t->id }}')">{{ __('workspaces.task.unarchive') }}</button>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>

        <div class="ag-pop-foot">
            <span class="ag-spacer"></span>
            @if ($overview['archived'] > 0)
                <button type="button" class="ag-text-btn ag-muted" wire:click="$toggle('showArchived')">{{ __('workspaces.switcher.archive') }} ({{ $overview['archived'] }})</button>
            @endif
        </div>
    </div>
</div>
