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
        tab: 'code',
        split: readSplit(),
        top: 0,
        resizing: false,

        init() {
            const measure = () => {
                this.top = Math.max(0, Math.round(this.$el.getBoundingClientRect().top + window.scrollY));
            };
            measure();
            window.addEventListener('resize', measure);
            // Banners above (status, usage limit) appear and disappear.
            new ResizeObserver(measure).observe(document.querySelector('.fi-main') ?? document.body);
        },

        style() {
            return `--ws-split:${this.split}%;--ws-top:${this.top}px`;
        },

        startResize(event) {
            this.resizing = true;
            const rect = this.$el.getBoundingClientRect();
            const move = (e) => {
                this.split = Math.min(MAX, Math.max(MIN, ((e.clientX - rect.left) / rect.width) * 100));
            };
            const stop = () => {
                this.resizing = false;
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
                this.save();
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop);
            move(event);
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
