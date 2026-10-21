/**
 * Live side of a workspace thread (Alpine data for the `workspace.thread-chat`
 * Livewire component). Streams Claude's output from the workspace presence
 * channel, shows who is here and who is typing, and asks Livewire to re-render
 * whenever something was persisted — including after a websocket reconnect.
 */
export function argosThreadStream(config) {
    return {
        threadId: config.threadId,
        status: config.status,
        live: '',
        thinking: '',
        members: [],
        typing: {},
        channel: null,
        refreshTimer: null,
        threadsTimer: null,
        pendingEdits: {},
        changeTimer: null,
        lastWhisper: 0,
        dragging: false,
        commands: [],
        suggestions: [],
        suggestIndex: 0,
        suggestToken: null,
        fileTimer: null,

        init() {
            this.$watch('live', () => this.scrollDown());
            if (!window.Echo || !config.workspaceId) {
                return;
            }

            this.channel = window.Echo.join(`workspace.${config.workspaceId}`)
                .here((members) => { this.members = members; })
                .joining((member) => { this.members = [...this.members.filter((m) => m.id !== member.id), member]; })
                .leaving((member) => { this.members = this.members.filter((m) => m.id !== member.id); })
                .listen('.thread.stream', (payload) => this.onStream(payload))
                .listenForWhisper('typing', (who) => this.onTyping(who));

            // After a reconnect we may have missed events: re-render from the DB.
            const connection = window.Echo.connector?.pusher?.connection;
            connection?.bind('connected', () => this.scheduleRefresh());
        },

        destroy() {
            if (window.Echo && config.workspaceId) {
                window.Echo.leave(`workspace.${config.workspaceId}`);
            }
        },

        onStream(payload) {
            // The switcher and the hint chip follow every thread of the workspace.
            this.announceThreads();
            // File edits of any thread feed the review's live hint.
            for (const event of payload.events ?? []) {
                if (event.type === 'tool.started') {
                    this.trackEdit(event.data);
                } else if (event.type === 'tool.result') {
                    this.announceEdit(event.data);
                    this.announceWorkspaceChange();
                }
            }
            if (payload.thread_id === '_workspace') {
                // Workspace-level change (quality gates): the Review tab reloads.
                window.dispatchEvent(new CustomEvent('argos-gates-updated'));
                return;
            }
            if (payload.thread_id !== this.threadId) {
                // Another thread changed: the switcher shows its status.
                this.scheduleRefresh(600);
                return;
            }

            let persisted = false;
            for (const event of payload.events) {
                switch (event.type) {
                    case 'assistant.delta':
                        this.live += event.data.text ?? '';
                        break;
                    case 'thinking.delta':
                        this.thinking += event.data.text ?? '';
                        break;
                    case 'assistant.message':
                        this.live = '';
                        this.thinking = '';
                        persisted = true;
                        break;
                    case 'turn.completed':
                        // The Review tab offers a refresh after Claude changed files.
                        window.dispatchEvent(new CustomEvent('argos-turn-completed', { detail: { threadId: this.threadId } }));
                        persisted = true;
                        break;
                    case 'status':
                        this.status = event.data.state;
                        if (this.status !== 'running') {
                            this.thinking = '';
                        }
                        persisted = true;
                        break;
                    default:
                        if (event.event_id !== undefined || event.type === 'user.accepted' || event.type === 'session.started') {
                            persisted = true;
                        }
                }
            }

            if (persisted) {
                this.scheduleRefresh();
            }
        },

        /** Remember edits by tool id; the file changes once the result arrives. */
        trackEdit(data) {
            const input = data?.input ?? {};
            const path = input.file_path ?? input.notebook_path;
            if (!['Edit', 'MultiEdit', 'Write', 'NotebookEdit'].includes(data?.name) || typeof path !== 'string') {
                return;
            }
            const lines = (text) => (typeof text === 'string' && text !== '' ? text.split('\n').length : 0);
            const edits = data.name === 'MultiEdit' && Array.isArray(input.edits) ? input.edits : [input];
            this.pendingEdits[data.tool_use_id] = {
                path: path.replace(/^\/workspace\//, ''),
                add: edits.reduce((n, e) => n + lines(e.new_string ?? e.content ?? ''), 0),
                del: edits.reduce((n, e) => n + lines(e.old_string ?? ''), 0),
            };
        },

        /** The review shows "Claude just changed X" (see review-panel.blade.php). */
        announceEdit(data) {
            const edit = this.pendingEdits[data?.tool_use_id];
            if (!edit) {
                return;
            }
            delete this.pendingEdits[data.tool_use_id];
            if (!data.is_error) {
                window.dispatchEvent(new CustomEvent('argos-file-changed', { detail: edit }));
            }
        },

        /** Any tool may have touched files (Bash, composer …): sidebar and tray refresh. */
        announceWorkspaceChange() {
            clearTimeout(this.changeTimer);
            this.changeTimer = setTimeout(() => window.dispatchEvent(new CustomEvent('argos-workspace-changed')), 2500);
        },

        announceThreads() {
            clearTimeout(this.threadsTimer);
            this.threadsTimer = setTimeout(() => window.dispatchEvent(new CustomEvent('argos-threads-changed')), 700);
        },

        scheduleRefresh(delay = 150) {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => {
                this.$wire.$refresh().then(() => this.scrollDown());
            }, delay);
        },

        typed() {
            const now = Date.now();
            if (!this.channel || now - this.lastWhisper < 2000) {
                return;
            }
            this.lastWhisper = now;
            this.channel.whisper('typing', { id: config.userId, name: config.userName, threadId: this.threadId });
        },

        onTyping(who) {
            if (who.threadId !== this.threadId || who.id === config.userId) {
                return;
            }
            this.typing = { ...this.typing, [who.id]: { name: who.name, at: Date.now() } };
            setTimeout(() => {
                const entry = this.typing[who.id];
                if (entry && Date.now() - entry.at >= 3000) {
                    const { [who.id]: _gone, ...rest } = this.typing;
                    this.typing = rest;
                }
            }, 3100);
        },

        typingText() {
            const names = Object.values(this.typing).map((t) => t.name);
            if (names.length === 0) {
                return '';
            }
            return config.labels.typing.replace('__NAME__', names.join(', '));
        },

        /** Upload pasted or dropped images through Livewire. */
        attach(fileList) {
            const images = Array.from(fileList || []).filter((f) => f.type.startsWith('image/'));
            if (images.length > 0) {
                this.$wire.uploadMultiple('uploads', images, () => {}, () => {});
            }
        },

        pasted(event) {
            const files = Array.from(event.clipboardData?.files || []);
            if (files.some((f) => f.type.startsWith('image/'))) {
                event.preventDefault();
                this.attach(files);
            }
        },

        /** Enter sends unless a suggestion list is open. */
        onComposerKeydown(event) {
            if (this.suggestions.length > 0) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const n = this.suggestions.length;
                    this.suggestIndex = (this.suggestIndex + (event.key === 'ArrowDown' ? 1 : n - 1)) % n;
                    return;
                }
                const item = this.suggestions[this.suggestIndex];
                const complete = event.key === 'Enter' && this.suggestToken?.kind === 'command'
                    && item?.value.trim() === this.$refs.composer?.value.trim();
                if ((event.key === 'Enter' || event.key === 'Tab') && !complete) {
                    event.preventDefault();
                    this.choose(this.suggestIndex);
                    return;
                }
                if (complete) {
                    // `/gates` typed out: Enter runs it.
                    this.suggestions = [];
                    this.suggestToken = null;
                }
                if (event.key === 'Escape') {
                    this.suggestions = [];
                    return;
                }
            }
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.$wire.send();
            }
        },

        /** Find an `@file` or leading `/command` token at the caret. */
        suggest() {
            const el = this.$refs.composer;
            if (!el) {
                return;
            }
            const before = el.value.slice(0, el.selectionStart);

            const command = before.match(/^\/([a-z0-9:_-]*)$/i);
            if (command) {
                const q = command[1].toLowerCase();
                this.suggestToken = { start: 0, end: before.length, kind: 'command' };
                this.suggestions = this.commands
                    .filter((c) => c.name.toLowerCase().startsWith(q))
                    .slice(0, 10)
                    .map((c) => ({ value: `/${c.name} `, label: `/${c.name}`, hint: c.description }));
                this.suggestIndex = 0;
                return;
            }

            const mention = before.match(/(^|\s)@([^\s@]*)$/);
            if (mention) {
                const q = mention[2];
                const start = before.length - q.length - 1;
                this.suggestToken = { start, end: before.length, kind: 'file' };
                clearTimeout(this.fileTimer);
                this.fileTimer = setTimeout(() => {
                    this.$wire.searchFiles(q).then((files) => {
                        if (!this.suggestToken || this.suggestToken.kind !== 'file') {
                            return;
                        }
                        this.suggestions = files.map((f) => ({ value: `@${f} `, label: f, hint: '' }));
                        this.suggestIndex = 0;
                    });
                }, 150);
                return;
            }

            this.suggestToken = null;
            this.suggestions = [];
        },

        choose(index) {
            const el = this.$refs.composer;
            const item = this.suggestions[index];
            if (!el || !item || !this.suggestToken) {
                return;
            }
            const value = el.value;
            el.value = value.slice(0, this.suggestToken.start) + item.value + value.slice(this.suggestToken.end);
            const caret = this.suggestToken.start + item.value.length;
            el.setSelectionRange(caret, caret);
            el.dispatchEvent(new Event('input'));
            this.suggestions = [];
            this.suggestToken = null;
            el.focus();
        },

        initials(name) {
            return (name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
        },

        scrollDown() {
            this.$nextTick(() => {
                const feed = this.$refs.feed;
                if (feed) {
                    feed.scrollTop = feed.scrollHeight;
                }
            });
        },
    };
}
