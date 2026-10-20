@php
    use App\Enums\ApprovalKind;
    use App\Enums\ApprovalStatus;
    use App\Enums\PermissionMode;
    use App\Enums\ThreadEventType;
    use App\Enums\ThreadStatus;
    use App\Livewire\Workspace\ThreadChat;
    use App\Support\ThreadEventFormatter as Fmt;

    /** @var \App\Models\Workspace $workspace */
    /** @var \App\Models\Thread|null $thread */
    $streamConfig = [
        'workspaceId' => $workspace->id,
        'threadId' => $thread?->id,
        'userId' => $user->id,
        'userName' => $user->name,
        'status' => $thread?->status->value,
        'labels' => [
            'typing' => __('workspaces.thread.typing', ['name' => '__NAME__']),
        ],
    ];
@endphp

<div class="ws-chat" x-data="window.argosThreadStream ? window.argosThreadStream(@js($streamConfig)) : {}" x-init="$nextTick(() => scrollDown?.())">
    {{-- Thread switcher + presence --}}
    <div class="ws-chat-head">
        <div class="ws-thread-picker">
            @if ($threads->isNotEmpty())
                <select class="ws-select" wire:change="selectThread($event.target.value)" aria-label="{{ __('workspaces.thread.title') }}">
                    @foreach ($threads as $t)
                        <option value="{{ $t->id }}" @selected($thread?->id === $t->id)>{{ $t->title }} · {{ $t->status->label() }}</option>
                    @endforeach
                </select>
            @endif
            @if ($canWrite)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="startCreating">
                    @svg('heroicon-o-plus') {{ __('workspaces.thread.new') }}
                </button>
            @endif
        </div>

        <div class="ws-presence" x-show="members && members.length" x-cloak>
            <span class="ws-presence-label">{{ __('workspaces.thread.present') }}</span>
            <template x-for="m in members" :key="m.id">
                <span class="ws-avatar" :title="m.name" x-text="initials(m.name)"></span>
            </template>
        </div>
    </div>

    {{-- New thread form --}}
    @if ($creating && $canWrite)
        <div class="card card-pad ws-new-thread">
            @if ($agents->isEmpty())
                <p class="muted">{{ __('workspaces.thread.no_agent') }}</p>
                <a class="btn btn-secondary btn-sm" href="{{ \App\Filament\Admin\Resources\AgentCredentialResource::getUrl('create') }}">{{ __('workspaces.thread.add_agent') }}</a>
            @else
                <form wire:submit="createThread" class="ws-form">
                    <label class="ws-field">
                        <span>{{ __('workspaces.thread.title') }}</span>
                        <input type="text" class="ws-input" wire:model="newTitle" maxlength="120" placeholder="{{ __('workspaces.thread.title_default') }}">
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.thread.agent') }}</span>
                        <select class="ws-select" wire:model="newAgentId">
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }}{{ $agent->user ? ' · '.$agent->user->name : ' · '.__('worker.credentials.fields.owner_shared') }}</option>
                            @endforeach
                        </select>
                        <small class="muted">{{ __('workspaces.thread.agent_help') }}</small>
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.thread.model') }}</span>
                        <select class="ws-select" wire:model="newModel">
                            <option value="">—</option>
                            @foreach ($models as $model)
                                <option value="{{ $model->value }}">{{ $model->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ws-field">
                        <span>{{ __('workspaces.thread.mode') }}</span>
                        <select class="ws-select" wire:model="newMode">
                            @foreach ($modes as $mode)
                                @if ($mode !== PermissionMode::BypassPermissions || $isOwner)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    @error('newAgentId') <p class="t-err">{{ $message }}</p> @enderror
                    <p class="muted ws-hint">{{ __('workspaces.thread.shared_files_hint') }}</p>
                    <div class="ws-form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('workspaces.thread.create') }}</button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if ($thread === null)
        @unless ($creating)
            <div class="ws-empty muted">{{ __('workspaces.thread.none') }}</div>
        @endunless
    @else
        {{-- History --}}
        <div class="ws-feed" x-ref="feed">
            @if ($hasOlder)
                <button type="button" class="btn btn-ghost btn-sm ws-older" wire:click="loadOlder">{{ __('workspaces.thread.load_older') }}</button>
            @endif

            @foreach ($events as $event)
                @php $p = $event->payload; @endphp
                @switch ($event->type)
                    @case (ThreadEventType::UserMessage)
                        <div class="ws-msg ws-msg-user" wire:key="ev-{{ $event->id }}">
                            <span class="ws-avatar" title="{{ $p['author_name'] ?? '' }}">{{ \Illuminate\Support\Str::of($p['author_name'] ?? '?')->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}</span>
                            <div class="ws-bubble">
                                <div class="ws-who">
                                    {{ $event->author_user_id === $user->id ? __('workspaces.events.you') : ($p['author_name'] ?? '') }}
                                    @if (! isset($p['accepted_at']) && $busy)
                                        <span class="badge badge-draft">{{ __('workspaces.thread.queued') }}</span>
                                    @endif
                                </div>
                                <div class="ws-text">{{ $p['text'] ?? '' }}</div>
                            </div>
                        </div>
                        @break

                    @case (ThreadEventType::AssistantMessage)
                        <div class="ws-msg ws-msg-claude" wire:key="ev-{{ $event->id }}">
                            <span class="ws-avatar ws-avatar-claude">C</span>
                            <div class="ws-bubble">
                                <div class="ws-who">{{ __('workspaces.events.claude') }}</div>
                                <div class="ws-md prose prose-sm">{{ Fmt::markdown((string) ($p['text'] ?? '')) }}</div>
                            </div>
                        </div>
                        @break

                    @case (ThreadEventType::Thinking)
                        <div class="ws-line ws-think" wire:key="ev-{{ $event->id }}" x-data="{ open: false }">
                            <span class="as-ico">✳</span>
                            <span class="ws-line-main">
                                <span class="as-tag">{{ __('workspaces.events.thinking') }}</span>
                                <span class="as-toggle" x-on:click="open = !open">{{ __('workspaces.events.show_more') }}</span>
                                <div class="as-more" x-show="open" x-cloak>{{ $p['text'] ?? '' }}</div>
                            </span>
                        </div>
                        @break

                    @case (ThreadEventType::ToolCall)
                        @php
                            $result = $toolResults->get((string) ($p['tool_use_id'] ?? ''));
                            $summary = Fmt::toolSummary((string) ($p['name'] ?? ''), $p['input'] ?? null);
                        @endphp
                        <div class="ws-line ws-tool" wire:key="ev-{{ $event->id }}" x-data="{ open: false }">
                            <span class="as-ico">◆</span>
                            <span class="ws-line-main">
                                <span class="name">{{ $p['name'] ?? __('workspaces.events.tool') }}</span>
                                <span class="summary mono">{{ $summary }}</span>
                                <span class="as-toggle" x-on:click="open = !open">{{ __('workspaces.events.show_more') }}</span>
                                <div class="as-more mono" x-show="open" x-cloak>{{ Fmt::json($p['input'] ?? null) }}@if ($result)

────
{{ $result->payload['output'] ?? '' }}@endif</div>
                                @if ($result && ($result->payload['is_error'] ?? false))
                                    <span class="badge badge-failed">{{ __('workspaces.events.error') }}</span>
                                @endif
                            </span>
                        </div>
                        @break

                    @case (ThreadEventType::ApprovalRequested)
                        @php
                            $approval = $approvals->get((string) ($p['approval_id'] ?? ''));
                            $kind = ApprovalKind::tryFrom((string) ($p['kind'] ?? 'tool')) ?? ApprovalKind::Tool;
                            $input = is_array($p['input'] ?? null) ? $p['input'] : [];
                        @endphp
                        @if ($approval)
                            @include('livewire.workspace.partials.approval-card', ['approval' => $approval, 'kind' => $kind, 'input' => $input, 'p' => $p, 'canWrite' => $canWrite, 'event' => $event])
                        @endif
                        @break

                    @case (ThreadEventType::TurnCompleted)
                        <div class="ws-divider" wire:key="ev-{{ $event->id }}">
                            <span>{{ __('workspaces.events.turn_done') }}</span>
                            @if (($p['duration_ms'] ?? null) !== null)
                                <span class="mono">{{ number_format(((int) $p['duration_ms']) / 1000, 1) }}s</span>
                            @endif
                        </div>
                        @break

                    @case (ThreadEventType::Error)
                        <div class="ws-alert ws-alert-error" wire:key="ev-{{ $event->id }}">
                            @svg('heroicon-o-exclamation-triangle')
                            <span>{{ $p['message'] ?? __('workspaces.events.error') }}</span>
                        </div>
                        @break

                    @case (ThreadEventType::UsageLimit)
                        <div class="ws-alert ws-alert-warn" wire:key="ev-{{ $event->id }}">
                            @svg('heroicon-o-clock')
                            <span>{{ __('workspaces.thread.usage_limit', ['agent' => $thread->agentCredential?->name ?? '', 'time' => isset($p['resets_at']) ? \Illuminate\Support\Carbon::parse($p['resets_at'])->diffForHumans() : __('workspaces.thread.usage_limit_unknown')]) }}</span>
                        </div>
                        @break

                    @case (ThreadEventType::Notice)
                        <div class="ws-notice muted" wire:key="ev-{{ $event->id }}">
                            {{ match ($p['kind'] ?? '') {
                                'interrupted' => __('workspaces.events.interrupted', ['name' => $p['author_name'] ?? '']),
                                'permission_mode' => __('workspaces.events.permission_mode', ['name' => $p['author_name'] ?? '', 'mode' => PermissionMode::tryFrom((string) ($p['mode'] ?? ''))?->label() ?? '']),
                                'model' => __('workspaces.events.model', ['name' => $p['author_name'] ?? '', 'model' => $p['model'] ?? '']),
                                'shipped' => __('workspaces.events.shipped', ['name' => $p['author_name'] ?? '']),
                                default => '',
                            } }}
                            @if (($p['kind'] ?? '') === 'shipped' && ($p['url'] ?? '') !== '')
                                <a class="link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['url'] }}</a>
                            @endif
                        </div>
                        @break

                    @case (ThreadEventType::ApprovalResolved)
                        @if (($p['status'] ?? '') === ApprovalStatus::Cancelled->value)
                            <div class="ws-notice muted" wire:key="ev-{{ $event->id }}">{{ __('workspaces.approval.cancelled') }}</div>
                        @endif
                        @break
                @endswitch
            @endforeach

            {{-- Live (not yet persisted) output, fed by the websocket --}}
            <div class="ws-msg ws-msg-claude ws-live" x-show="live || thinking" x-cloak>
                <span class="ws-avatar ws-avatar-claude">C</span>
                <div class="ws-bubble">
                    <div class="ws-who">{{ __('workspaces.events.claude') }}</div>
                    <div class="ws-think-live muted" x-show="thinking && !live" x-text="thinking.slice(-400)"></div>
                    <div class="ws-text" x-text="live"></div>
                </div>
            </div>

            <div class="ws-working" x-show="status === 'running'" x-cloak>
                <span class="ws-dot"></span> {{ __('workspaces.thread.working') }}
            </div>
            <div class="ws-working ws-working-wait" x-show="status === 'waiting_input'" x-cloak>
                @svg('heroicon-o-hand-raised') {{ __('workspaces.thread.waiting') }}
            </div>
        </div>

        @if (ThreadChat::canResume($thread) && $canWrite)
            <div class="ws-alert ws-alert-warn">
                <span>{{ __('workspaces.thread.session_ended') }}</span>
                <button type="button" class="btn btn-secondary btn-sm" wire:click="resume">{{ __('workspaces.thread.resume') }}</button>
            </div>
        @endif

        {{-- Composer --}}
        @if ($canWrite)
            <div class="ws-composer">
                <div class="ws-typing muted" x-show="typingText()" x-text="typingText()" x-cloak></div>
                <textarea
                    class="respond-ta ws-ta"
                    rows="3"
                    wire:model="draft"
                    placeholder="{{ __('workspaces.thread.placeholder') }}"
                    x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send(); }"
                    x-on:input="typed()"
                ></textarea>
                <div class="ws-composer-bar">
                    <select class="ws-select ws-select-sm" wire:change="setMode($event.target.value)" aria-label="{{ __('workspaces.thread.mode') }}">
                        @foreach ($modes as $mode)
                            @if ($mode !== PermissionMode::BypassPermissions || $isOwner)
                                <option value="{{ $mode->value }}" @selected($thread->permission_mode === $mode)>{{ $mode->label() }}</option>
                            @endif
                        @endforeach
                    </select>
                    <select class="ws-select ws-select-sm" wire:change="setModel($event.target.value)" aria-label="{{ __('workspaces.thread.model') }}">
                        @foreach ($models as $model)
                            <option value="{{ $model->value }}" @selected($thread->model === $model->value)>{{ $model->label() }}</option>
                        @endforeach
                    </select>
                    @if ($thread->permission_mode === PermissionMode::BypassPermissions)
                        <span class="badge badge-failed" title="{{ __('workspaces.thread.bypass_warning') }}">@svg('heroicon-o-exclamation-triangle') {{ PermissionMode::BypassPermissions->label() }}</span>
                    @endif
                    <span class="ws-spacer"></span>
                    @if ((float) $thread->cost_usd > 0)
                        <span class="muted mono ws-cost" title="{{ __('workspaces.thread.cost') }}">{{ \App\Support\CostFormatter::usd((float) $thread->cost_usd) }}</span>
                    @endif
                    <button type="button" class="btn btn-danger btn-sm" wire:click="interrupt" x-show="status === 'running' || status === 'waiting_input'" x-cloak>
                        @svg('heroicon-o-stop') {{ __('workspaces.thread.stop') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                        @svg('heroicon-o-paper-airplane') {{ __('workspaces.thread.send') }}
                    </button>
                </div>
            </div>
        @else
            <div class="ws-viewer muted">{{ __('workspaces.thread.viewer_hint') }}</div>
        @endif
    @endif
</div>
