<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Workflow\UsageLimitManager;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class UsageLimitBanner extends Component
{
    public bool $active = false;

    public ?string $resetAt = null;

    public ?string $resetsIn = null;

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $manager = app(UsageLimitManager::class);
        $limit = $manager->current();
        $this->active = $manager->isActive();
        $this->resetAt = null;
        $this->resetsIn = null;

        if ($this->active && isset($limit['reset_at'])) {
            try {
                $target = Carbon::parse($limit['reset_at']);
                $seconds = (int) now()->diffInSeconds($target, false);

                if ($seconds > 0) {
                    $this->resetAt = $target->format('H:i \U\h\r');
                    $this->resetsIn = $this->formatSeconds($seconds);
                } else {
                    // Limit expired but cache not yet cleared — dismiss silently.
                    $this->active = false;
                    $manager->clear();
                }
            } catch (\Throwable) {
                // malformed timestamp — show banner without time info
            }
        }
    }

    public function dismiss(): void
    {
        app(UsageLimitManager::class)->clear();
        $this->active = false;
        $this->resetAt = null;
        $this->resetsIn = null;
    }

    private function formatSeconds(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        if ($h > 0) {
            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }

        return $m > 0 ? "{$m}m" : '<1m';
    }

    public function render(): View
    {
        return view('livewire.usage-limit-banner');
    }
}
