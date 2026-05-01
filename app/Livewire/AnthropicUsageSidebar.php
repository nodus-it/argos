<?php

declare(strict_types=1);

namespace App\Livewire;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class AnthropicUsageSidebar extends Component
{
    public ?array $fiveHour = null;

    public ?array $sevenDay = null;

    public bool $error = false;

    public function mount(): void
    {
        $this->loadUsage();
    }

    public function loadUsage(): void
    {
        $data = Cache::remember('anthropic_usage', 60, function () {
            try {
                $token = config('services.anthropic.token');

                if (empty($token)) {
                    return null;
                }

                $response = Http::withToken($token)
                    ->withHeaders([
                        'anthropic-beta' => 'oauth-2025-04-20',
                        'User-Agent' => 'claude-code/2.0.31',
                    ])
                    ->timeout(5)
                    ->get('https://api.anthropic.com/api/oauth/usage');

                if (! $response->successful()) {
                    return null;
                }

                return $response->json();
            } catch (\Throwable) {
                return null;
            }
        });

        if ($data === null) {
            $this->error = true;

            return;
        }

        $this->error = false;
        $this->fiveHour = $this->formatPeriod($data['five_hour'] ?? null);
        $this->sevenDay = $this->formatPeriod($data['seven_day'] ?? null);
    }

    /**
     * @param  array{utilization: float|null, resets_at: string|null}|null  $period
     * @return array{utilization: int, resets_in: string}|null
     */
    private function formatPeriod(?array $period): ?array
    {
        if ($period === null || $period['utilization'] === null) {
            return null;
        }

        $resetsIn = null;

        if (! empty($period['resets_at'])) {
            try {
                $resetsAt = Carbon::parse($period['resets_at']);
                $diff = now()->diffAsCarbonInterval($resetsAt, false);

                if ($diff->totalSeconds > 0) {
                    $resetsIn = $this->formatInterval($resetsAt);
                }
            } catch (\Throwable) {
                // ignore malformed timestamp
            }
        }

        return [
            'utilization' => (int) round($period['utilization']),
            'resets_in' => $resetsIn ?? '–',
        ];
    }

    private function formatInterval(Carbon $target): string
    {
        $seconds = (int) now()->diffInSeconds($target, false);

        if ($seconds <= 0) {
            return 'jetzt';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }

    public function render(): View
    {
        return view('livewire.anthropic-usage-sidebar');
    }
}
