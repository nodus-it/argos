<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Anthropic\CredentialStore;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class AnthropicUsageSidebar extends Component
{
    public ?array $fiveHour = null;

    public ?array $sevenDay = null;

    public function mount(): void
    {
        $this->loadUsage();
    }

    public function loadUsage(): void
    {
        // Show stale data while backing off from a previous error/rate-limit.
        if (Cache::has('anthropic_usage_backoff')) {
            $this->applyData(Cache::get('anthropic_usage'));

            return;
        }

        // Serve fresh cached data without hitting the API.
        if (($data = Cache::get('anthropic_usage')) !== null) {
            $this->applyData($data);

            return;
        }

        $token = app(CredentialStore::class)->getClaudeToken();

        if (empty($token)) {
            return;
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'anthropic-beta' => 'oauth-2025-04-20',
                    'User-Agent' => 'claude-code/2.0.31',
                ])
                ->timeout(5)
                ->get('https://api.anthropic.com/api/oauth/usage');

            if ($response->status() === 429) {
                Cache::put('anthropic_usage_backoff', true, 600);

                return;
            }

            // permission_error means the token lacks the user:profile scope —
            // this won't change without a different token, so back off for 24h.
            $errorType = $response->json('error.type');
            if ($errorType === 'permission_error') {
                Cache::put('anthropic_usage_backoff', true, 86400);

                return;
            }

            if (! $response->successful()) {
                Cache::put('anthropic_usage_backoff', true, 300);
                Log::channel('argos')->warning('Anthropic usage API error', ['status' => $response->status()]);

                return;
            }

            $data = $response->json();
            // Cache successful data for 30 min so stale data stays visible during backoff periods.
            Cache::put('anthropic_usage', $data, 1800);
            Cache::forget('anthropic_usage_backoff');
            $this->applyData($data);
        } catch (\Throwable $e) {
            Cache::put('anthropic_usage_backoff', true, 300);
            Log::channel('argos')->error('Anthropic usage API failed', ['error' => $e->getMessage()]);
        }
    }

    private function applyData(?array $data): void
    {
        if ($data === null) {
            $this->fiveHour = null;
            $this->sevenDay = null;

            return;
        }

        $this->fiveHour = $this->formatPeriod($data['five_hour'] ?? null);
        $this->sevenDay = $this->formatPeriod($data['seven_day'] ?? null);
    }

    /**
     * @param  array{utilization: float|null, resets_at: string|null}|null  $period
     * @return array{utilization: int, resets_in: string}|null
     */
    private function formatPeriod(?array $period): ?array
    {
        if ($period === null || ! isset($period['utilization']) || $period['utilization'] === null) {
            return null;
        }

        $resetsIn = null;

        if (! empty($period['resets_at'])) {
            try {
                $resetsAt = Carbon::parse($period['resets_at']);

                if ($resetsAt->isFuture()) {
                    $resetsIn = $this->formatInterval($resetsAt);
                }
            } catch (\Throwable) {
                // ignore malformed timestamp
            }
        }

        return [
            'utilization' => (int) round((float) $period['utilization']),
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
