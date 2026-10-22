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
<div class="wf-switcher" x-data="argosTaskSwitcher()"
     x-on:argos-tasks-changed.window="$wire.$refresh()"
     x-on:argos-switcher-toggle.window="toggle()"
     x-on:keydown.escape.prevent.stop="close()">
    <button type="button" class="wf-switch-btn" x-ref="button" x-on:click="toggle()"
            aria-haspopup="dialog" :aria-expanded="open" aria-controls="wf-switcher-pop">
        <span @class(['wf-dot', 'is-'.($currentGroup ?? 'done')]) aria-hidden="true"></span>
        <span class="wf-switch-title">{{ $current?->title ?? __('workspaces.task.new') }}</span>
        <span class="wf-switch-count">{{ trans_choice('workspaces.switcher.count', $count, ['count' => $count]) }}</span>
        <kbd class="wf-kbd wf-hide-narrow" x-text="isMac ? '⌘J' : 'Ctrl J'">⌘J</kbd>
        <x-wf.icon name="chevron-down" :size="16" :stroke="2" class="wf-muted" />
    </button>

    @if ($waitingElsewhere !== [])
        @php $first = $waitingElsewhere[0]['task']; @endphp
        <button type="button" class="wf-wait-chip" wire:click="select('{{ $first->id }}')" x-on:click="$dispatch('scroll-to-approval')"
                title="{{ $first->title }} · {{ $waitingElsewhere[0]['text'] }}">
            <span class="wf-dot is-waiting" aria-hidden="true"></span>
            {{ trans_choice('workspaces.switcher.waiting_chip', count($waitingElsewhere), ['count' => count($waitingElsewhere)]) }}
        </button>
    @endif

    <div id="wf-switcher-pop" class="wf-pop" role="dialog" aria-label="{{ __('workspaces.switcher.title') }}"
         x-show="open" x-cloak x-transition.opacity.duration.120ms
         x-on:click.outside="close(false)" x-on:keydown="onKey($event)">
        <div class="wf-pop-search">
            <x-wf.icon name="search" :size="16" :stroke="2" class="wf-muted" />
            <label class="sr-only" for="wf-task-search">{{ __('workspaces.switcher.search') }}</label>
            <input id="wf-task-search" type="text" x-ref="search" x-model="query" autocomplete="off"
                   placeholder="{{ __('workspaces.switcher.search') }} …" role="combobox" aria-controls="wf-task-list" :aria-activedescendant="activeId()">
            <kbd class="wf-kbd-plain mono">esc</kbd>
        </div>

        <div class="wf-pop-filter" role="group" aria-label="{{ __('workspaces.switcher.title') }}">
            <button type="button" @class(['wf-seg', 'is-on' => $mine]) wire:click="$set('mine', true)" aria-pressed="{{ $mine ? 'true' : 'false' }}">{{ __('workspaces.switcher.filter_mine') }}</button>
            <button type="button" @class(['wf-seg', 'is-on' => ! $mine]) wire:click="$set('mine', false)" aria-pressed="{{ $mine ? 'false' : 'true' }}">{{ __('workspaces.switcher.filter_all') }}</button>
        </div>

        <div class="wf-pop-list" id="wf-task-list" role="listbox" aria-label="{{ __('workspaces.switcher.title') }}" x-ref="list">
            @foreach ($groups as $group => $label)
                @php $rows = $overview[$group]; @endphp
                @continue($rows === [])
                <div class="wf-pop-group is-{{ $group }}" x-show="groupVisible(@js($group))">{{ mb_strtoupper($label) }} · {{ count($rows) }}</div>
                @foreach ($rows as $i => $row)
                    @php $t = $row['task']; $isCurrent = $t->id === $current?->id; @endphp
                    <div @class(['wf-pop-item', 'is-current' => $isCurrent, 'is-compact' => $group === TaskOverview::DONE])
                         id="wf-t-{{ $t->id }}" role="option" data-task="{{ $t->id }}" data-group="{{ $group }}"
                         data-title="{{ mb_strtolower($t->title) }}" @if ($group === TaskOverview::DONE && $i >= $doneLimit) data-more @endif
                         :aria-selected="activeId() === 'wf-t-{{ $t->id }}'" @if ($isCurrent) aria-current="true" @endif
                         x-show="visible($el)" wire:key="sw-{{ $t->id }}"
                         x-on:click="choose(@js($t->id))" x-on:mousemove="hover($el)">
                        <span @class(['wf-dot', 'is-'.$group]) aria-hidden="true"></span>
                        @if ($renamingId === $t->id)
                            <form wire:submit="rename" class="wf-pop-rename" x-on:click.stop>
                                <input type="text" class="wf-input" wire:model="renameTitle" maxlength="120" x-init="$nextTick(() => $el.focus())" x-on:keydown.stop>
                            </form>
                        @else
                            <span class="wf-pop-text">
                                <span class="wf-pop-title">
                                    @if ($t->auto_state)<x-wf.icon name="zap" :size="12" :stroke="2" class="wf-accent" label="{{ __('workspaces.auto.label') }}" />@endif
                                    {{ $t->title }}
                                </span>
                                @if ($group !== TaskOverview::DONE || $isCurrent)
                                    <span class="wf-pop-sub">{{ $row['text'] }}</span>
                                @endif
                                @if ($t->external_ref !== null || $t->pr_number !== null)
                                    <span class="wf-pop-refs">
                                        @if ($t->external_ref !== null)
                                            <span class="wf-ref-chip" title="{{ $t->external_ref['title'] ?? '' }}"><x-wf.icon name="link" :size="11" :stroke="2" /> {{ \App\Support\TaskRefs::ticketLabel($t) }}</span>
                                        @endif
                                        @if ($t->pr_number !== null)
                                            <span class="wf-ref-chip is-pr is-{{ $t->pr_state ?? 'open' }}"><x-wf.icon name="git-pr" :size="11" :stroke="2" /> #{{ $t->pr_number }}</span>
                                        @endif
                                    </span>
                                @endif
                            </span>
                        @endif
                        @if ($t->owner !== null && $t->owner_user_id !== $user->id)
                            <x-wf.avatar :user="$t->owner" title="{{ __('workspaces.switcher.owner', ['name' => $t->owner->name]) }}" aria-label="{{ __('workspaces.switcher.owner', ['name' => $t->owner->name]) }}" />
                        @endif
                        @if (($unread[$t->id] ?? 0) > 0 && ! $isCurrent)
                            <span class="wf-unread" title="{{ __('workspaces.switcher.unread') }}">{{ $unread[$t->id] }}</span>
                        @endif
                        <span class="wf-pop-time">{{ $t->updated_at?->diffForHumans(short: true) }}</span>
                        @if ($canWrite)
                            <span class="wf-pop-actions" x-on:click.stop>
                                <button type="button" class="wf-icon-btn wf-icon-btn-xs" wire:click="startRename('{{ $t->id }}')" title="{{ __('workspaces.task.rename') }}" aria-label="{{ __('workspaces.task.rename') }}">@svg('heroicon-o-pencil')</button>
                                <button type="button" class="wf-icon-btn wf-icon-btn-xs" wire:click="archive('{{ $t->id }}')" title="{{ __('workspaces.task.archive') }}" aria-label="{{ __('workspaces.task.archive') }}">@svg('heroicon-o-archive-box')</button>
                            </span>
                        @endif
                    </div>
                @endforeach
                @if ($group === TaskOverview::DONE && count($rows) > $doneLimit)
                    <button type="button" class="wf-pop-more" x-show="!showMore && query === ''" x-on:click="showMore = true">
                        {{ __('workspaces.switcher.show_more', ['count' => count($rows) - $doneLimit]) }}
                    </button>
                @endif
            @endforeach

            @if ($empty)
                <p class="wf-pop-empty">
                    {{ __('workspaces.task.none_short') }}
                    @if ($mine && $others > 0)
                        <button type="button" class="wf-text-btn" wire:click="$set('mine', false)">{{ trans_choice('workspaces.switcher.show_others', $others, ['count' => $others]) }}</button>
                    @endif
                </p>
            @endif

            @if ($showArchived)
                <div class="wf-pop-group">{{ mb_strtoupper(__('workspaces.switcher.archive')) }} · {{ $archived->count() }}</div>
                @foreach ($archived as $t)
                    <div class="wf-pop-item is-compact is-archived" wire:key="ar-{{ $t->id }}" data-title="{{ mb_strtolower($t->title) }}" x-show="visible($el)">
                        <span class="wf-dot is-done" aria-hidden="true"></span>
                        <button type="button" class="wf-pop-text wf-pop-link" wire:click="select('{{ $t->id }}')" x-on:click="close()">{{ $t->title }}</button>
                        @if ($canWrite)
                            <button type="button" class="wf-text-btn" wire:click="unarchive('{{ $t->id }}')">{{ __('workspaces.task.unarchive') }}</button>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>

        <div class="wf-pop-foot">
            @if ($canWrite)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-primary" x-on:click="close(); $dispatch('task-new')">
                    <x-wf.icon name="plus" :size="14" :stroke="2" /> {{ __('workspaces.task.new') }}
                </button>
            @endif
            <span class="wf-spacer"></span>
            @if ($overview['archived'] > 0)
                <button type="button" class="wf-text-btn wf-muted" wire:click="$toggle('showArchived')">{{ __('workspaces.switcher.archive') }} ({{ $overview['archived'] }})</button>
            @endif
        </div>
    </div>
</div>
