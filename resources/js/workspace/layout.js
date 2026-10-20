/**
 * Workspace page layout: fills the viewport below its own top and lets the
 * gutter set the chat/tools split. The split is a per-browser convenience
 * (localStorage, failures ignored).
 */
const SPLIT_KEY = 'argos.workspace.split';
const MIN = 25;
const MAX = 70;

function readSplit() {
    try {
        const value = Number(window.localStorage.getItem(SPLIT_KEY));
        return value >= MIN && value <= MAX ? value : 40;
    } catch {
        return 40;
    }
}

export function argosWorkspaceLayout() {
    return {
        highlight: null,
        split: readSplit(),
        top: 0,
        resizing: false,

        init() {
            const measure = () => {
                this.top = Math.max(0, Math.round(this.$root.getBoundingClientRect().top + window.scrollY));
            };
            measure();
            window.addEventListener('resize', measure);
            // Banners above (status, usage limit) appear and disappear.
            new ResizeObserver(measure).observe(document.querySelector('.fi-main') ?? document.body);
        },

        /** `/preview` and `/review`: point at the control instead of switching tabs. */
        show(target) {
            if (target === 'review') {
                this.$refs.review?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            this.highlight = target;
            setTimeout(() => {
                this.highlight = null;
            }, 1600);
        },

        style() {
            return `--ws-split:${this.split}%;--ws-top:${this.top}px`;
        },

        startResize(event) {
            this.resizing = true;
            // $root, not $el: inside the handler $el is the gutter itself.
            const rect = this.$root.getBoundingClientRect();
            // Keep the grab offset, so the divider does not jump to the pointer.
            const startX = event.clientX;
            const startSplit = this.split;
            const move = (e) => {
                this.split = Math.min(MAX, Math.max(MIN, startSplit + ((e.clientX - startX) / rect.width) * 100));
            };
            const stop = () => {
                this.resizing = false;
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
                this.save();
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop);
        },

        nudge(delta) {
            this.split = Math.min(MAX, Math.max(MIN, this.split + delta));
            this.save();
        },

        save() {
            try {
                window.localStorage.setItem(SPLIT_KEY, String(Math.round(this.split)));
            } catch {
                // Private mode or blocked storage: the split just isn't remembered.
            }
        },
    };
}
