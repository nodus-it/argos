/**
 * Seconds since a fixed start (epoch ms). Derived from the clock on every
 * tick instead of counting up, so re-initialised copies of the component
 * (Livewire re-renders) cannot add up to a running-ahead counter.
 */
export function argosElapsed(startMs) {
    return {
        now: Date.now(),
        timer: null,
        init() {
            this.timer = setInterval(() => {
                this.now = Date.now();
            }, 1000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        get seconds() {
            return startMs === null ? 0 : Math.max(0, Math.floor((this.now - startMs) / 1000));
        },
    };
}
