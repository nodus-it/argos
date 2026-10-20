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
        lastWhisper: 0,

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
