<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily npm-registry poll for every registered agent's pinned package.
// Result is persisted in agent_versions; the dashboard widget reads from
// there. Manual trigger: `php artisan argos:check-agent-versions`.
Schedule::command('argos:check-agent-versions')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Poll issue providers and check concept-comment reactions on a configurable
// interval (ARGOS_POLL_INTERVAL_MINUTES, default 5; set to 1 locally for fast
// feedback). Providers don't push reaction events, so approvals are polled too.
// Manual triggers: `php artisan argos:poll-issues`,
// `php artisan argos:check-concept-approvals`.
$pollCron = '*/'.config('argos.poll_interval_minutes', 5).' * * * *';

Schedule::command('argos:poll-issues')
    ->cron($pollCron)
    ->withoutOverlapping();

Schedule::command('argos:check-concept-approvals')
    ->cron($pollCron)
    ->withoutOverlapping();

// Tear down live demos past their TTL (preview.ttl_hours). Dispatches
// StopDemoJob to the queue (the scheduler has no docker socket).
// Manual trigger: `php artisan argos:cleanup-demos`.
Schedule::command('argos:cleanup-demos')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Reap orphaned worker/sidecar run resources left by a hard-killed phase job
// (skipped finally-block teardown). Dispatches ReapOrphanedRunsJob to the
// queue (the scheduler has no docker socket).
// Manual trigger: `php artisan argos:cleanup-orphans`.
Schedule::command('argos:cleanup-orphans')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
