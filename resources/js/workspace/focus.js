/**
 * Workspace "Fokus" page: review mode (sidebar widens into the review
 * panel, URL `?review=1&file=…`), maximised review, the context drawer on
 * narrow screens and the global shortcuts (⌘J / Ctrl+J, Esc).
 */
export function argosWorkspaceFocus() {
    return {
        review: false,
        maximized: false,
        drawer: false,

        init() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('review') === '1') {
                this.review = true;
            }
            window.addEventListener('review-open', (event) => this.openReview(event.detail?.file ?? null));
        },

        openReview(file = null) {
            this.review = true;
            this.drawer = false;
            this.syncUrl(file);
            window.dispatchEvent(new CustomEvent('argos-review-opened', { detail: { file } }));
        },

        closeReview() {
            this.review = false;
            this.maximized = false;
            this.syncUrl(null, true);
        },

        syncUrl(file, clear = false) {
            const url = new URL(window.location.href);
            if (clear) {
                url.searchParams.delete('review');
                url.searchParams.delete('file');
            } else {
                url.searchParams.set('review', '1');
                if (file) {
                    url.searchParams.set('file', file);
                }
            }
            window.history.replaceState(window.history.state, '', url);
        },

        onKey(event) {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'j') {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('argos-switcher-toggle'));
                return;
            }
            if (event.key === 'Escape' && !event.defaultPrevented) {
                if (document.querySelector('.fi-modal-open, [x-data] .fi-modal[aria-modal="true"]')) {
                    return;
                }
                if (this.review) {
                    this.closeReview();
                } else if (this.drawer) {
                    this.drawer = false;
                }
            }
        },
    };
}

/** Thread switcher popover: search, groups, arrow keys + Enter. */
export function argosThreadSwitcher() {
    return {
        open: false,
        query: '',
        showMore: false,
        active: null,
        isMac: /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent),

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
            this.query = '';
            this.showMore = false;
            this.$nextTick(() => {
                const current = this.$refs.list.querySelector('[aria-current="true"]');
                this.active = current?.id ?? this.items()[0]?.id ?? null;
                this.$refs.search.focus();
            });
        },

        close(returnFocus = true) {
            if (!this.open) {
                return;
            }
            this.open = false;
            if (returnFocus) {
                this.$refs.button.focus();
            }
        },

        visible(el) {
            if (this.query !== '') {
                return (el.dataset.title || '').includes(this.query.toLowerCase());
            }
            return !('more' in el.dataset) || this.showMore;
        },

        groupVisible(group) {
            return Array.from(this.$refs.list.querySelectorAll(`[data-group="${group}"]`)).some((el) => this.visible(el));
        },

        items() {
            return Array.from(this.$refs.list.querySelectorAll('[role="option"]')).filter((el) => this.visible(el));
        },

        activeId() {
            return this.active;
        },

        hover(el) {
            this.active = el.id;
        },

        onKey(event) {
            const items = this.items();
            const index = items.findIndex((el) => el.id === this.active);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const next = items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
                this.active = next?.id ?? null;
                next?.scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && event.target === this.$refs.search) {
                event.preventDefault();
                const chosen = items[index] ?? items[0];
                if (chosen) {
                    this.choose(chosen.dataset.thread);
                }
            }
        },

        choose(id) {
            this.$wire.select(id);
            this.close();
        },
    };
}
