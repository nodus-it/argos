/** Matching lines shown at most while searching a gate log. */
const MATCH_LIMIT = 2000;

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** `line` as HTML with every (case-insensitive) occurrence of `needle` marked. */
function highlight(line, needle) {
    const lower = line.toLowerCase();
    let out = '';
    let at = 0;
    for (let i = lower.indexOf(needle); i !== -1; i = lower.indexOf(needle, at)) {
        out += escapeHtml(line.slice(at, i)) + '<mark>' + escapeHtml(line.slice(i, i + needle.length)) + '</mark>';
        at = i + needle.length;
    }

    return out + escapeHtml(line.slice(at));
}

/**
 * Gate detail slide-over: opens on the window event `gate-detail-open`
 * ({taskId, gate}), loads the detail through Livewire and filters the log
 * client-side (matching lines with their line numbers, matches marked).
 */
export function argosGateDetail() {
    return {
        open: false,
        q: '',
        html: '',
        count: 0,
        capped: false,

        show(detail) {
            if (!detail?.taskId || !detail?.gate) {
                return;
            }
            this.open = true;
            this.reset();
            this.$wire.show(detail.taskId, detail.gate);
            this.$nextTick(() => this.$refs.close?.focus());
        },

        close() {
            if (!this.open) {
                return;
            }
            this.open = false;
            this.reset();
            this.$wire.hide();
        },

        reset() {
            this.q = '';
            this.html = '';
            this.count = 0;
            this.capped = false;
        },

        get searching() {
            return this.q.trim() !== '';
        },

        search() {
            const needle = this.q.trim().toLowerCase();
            if (needle === '') {
                this.reset();
                return;
            }
            const lines = (this.$refs.raw?.textContent ?? '').split('\n');
            const out = [];
            let count = 0;
            lines.forEach((line, i) => {
                if (!line.toLowerCase().includes(needle)) {
                    return;
                }
                count++;
                if (out.length < MATCH_LIMIT) {
                    out.push('<span class="wf-gd-ln">' + (i + 1) + '</span>' + highlight(line, needle));
                }
            });
            this.count = count;
            this.capped = count > MATCH_LIMIT;
            this.html = out.join('\n');
        },

        openFile(path) {
            this.close();
            window.dispatchEvent(new CustomEvent('review-open', { detail: { file: path } }));
        },
    };
}
