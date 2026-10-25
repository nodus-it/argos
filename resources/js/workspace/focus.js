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
        commentCount: 0,

        init() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('review') === '1') {
                this.review = true;
            }
            window.addEventListener('review-open', (event) => this.openReview(event.detail?.file ?? null));
            window.addEventListener('review-maximize', () => { this.maximized = !this.maximized; });
            window.addEventListener('argos-review-count', (event) => { this.commentCount = Number(event.detail) || 0; });
            // `/review`, `/gates`, `/preview` and the gates action (Livewire `workspace-focus`).
            window.addEventListener('workspace-focus', (event) => {
                const target = event.detail?.target;
                if (target === 'review') {
                    this.openReview(null);
                    return;
                }
                // `/preview`, `/gates`: show that part of the sidebar, unfolded and marked.
                if (this.review) {
                    this.closeReview();
                }
                if (window.matchMedia('(max-width: 1100px)').matches) {
                    this.drawer = true;
                }
                const key = target === 'preview' ? 'env' : target;
                window.dispatchEvent(new CustomEvent('argos-fold-open', { detail: { key } }));
                window.setTimeout(() => {
                    const section = document.getElementById('ctx-' + key);
                    if (!section) {
                        return;
                    }
                    section.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    section.classList.add('is-flash');
                    window.setTimeout(() => section.classList.remove('is-flash'), 1600);
                }, 80);
            });
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
            window.dispatchEvent(new CustomEvent('argos-review-closed'));
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
            const typing = event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName));
            if (event.key === '?' && !typing && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('argos-shortcuts'));
                return;
            }
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

/** Task switcher popover: search, groups, arrow keys + Enter. */
export function argosTaskSwitcher() {
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
                    this.choose(chosen.dataset.task);
                }
            }
        },

        choose(id) {
            this.$wire.select(id);
            this.close();
        },
    };
}

/** Review diff: picking lines (Shift-click extends) and the inline comment editor. */
export function argosReview(labelTemplate) {
    return {
        draft: null,
        change: null,
        changed: [],
        // Phones: the file list is folded behind a toggle above the diff.
        filesOpen: false,

        /** Claude edited a file: hint, don't swap the diff under the reader. */
        fileChanged(edit) {
            if (!this.$wire.active || !edit?.path) {
                return;
            }
            this.change = edit;
            if (!this.changed.includes(edit.path)) {
                this.changed = [...this.changed, edit.path];
            }
        },

        changeText(template) {
            return this.change ? template.replace('__F__', this.change.path.split('/').pop()).replace('__A__', this.change.add).replace('__D__', this.change.del) : '';
        },

        showChange(currentPath) {
            const path = this.change?.path;
            this.change = null;
            if (path && path !== currentPath) {
                this.$wire.selectFile(path);
            } else {
                this.$wire.$refresh();
            }
        },

        pick(side, line, event) {
            if (event.shiftKey && this.draft && this.draft.side === side) {
                this.draft.end = Math.max(line, this.draft.anchor);
                this.draft.start = Math.min(line, this.draft.anchor);
                return;
            }
            this.draft = { side, start: line, end: line, anchor: line, body: this.draft?.body ?? '' };
        },

        inDraft(side, line) {
            return this.draft !== null && this.draft.side === side && line >= this.draft.start && line <= this.draft.end;
        },

        draftLabel() {
            if (!this.draft) {
                return '';
            }
            const range = this.draft.start === this.draft.end ? `${this.draft.start}` : `${this.draft.start}–${this.draft.end}`;
            return labelTemplate.replace('__L__', range);
        },

        save(path) {
            if (!this.draft || this.draft.body.trim() === '') {
                return;
            }
            const { side, start, end, body } = this.draft;
            this.$wire.addComment(path, side, start, end, body).then(() => { this.draft = null; });
        },
    };
}

/**
 * A sidebar section that folds (state per section, remembered in the
 * browser). Spread into the section's x-data: `{ ...argosFold('pr') }`.
 */
export function argosFold(key, openByDefault = true) {
    const storageKey = `argos-fold-${key}`;
    let open = openByDefault;
    try {
        const stored = window.localStorage.getItem(storageKey);
        if (stored !== null) {
            open = stored === '1';
        }
    } catch {
        // Storage blocked: fall back to the default.
    }

    return {
        open,
        init() {
            window.addEventListener('argos-fold-open', (event) => {
                if (event.detail?.key === key) {
                    this.open = true;
                }
            });
        },
        toggleFold() {
            this.open = !this.open;
            try {
                window.localStorage.setItem(storageKey, this.open ? '1' : '0');
            } catch {
                // Storage blocked: the state lasts until the next reload.
            }
        },
    };
}
