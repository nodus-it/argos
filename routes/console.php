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

// Poll issue providers and check plan-comment reactions on a configurable
// interval (ARGOS_POLL_INTERVAL_MINUTES, default 5; set to 1 locally for fast
// feedback). Providers don't push reaction events, so approvals are polled too.
// Manual triggers: `php artisan argos:poll-issues`,
// `php artisan argos:check-plan-approvals`.
$pollCron = '*/'.config('argos.poll_interval_minutes', 5).' * * * *';

Schedule::command('argos:poll-issues')
    ->cron($pollCron)
    ->withoutOverlapping();

// 👍 on the plan comment of an issue-started automatic run approves the plan.
Schedule::command('argos:check-plan-approvals')
    ->cron($pollCron)
    ->withoutOverlapping();

// Tear down live demos past their TTL (preview.ttl_hours). Dispatches
// StopWorkspaceDemoJob to the queue (the scheduler has no docker socket).
// Manual trigger: `php artisan argos:cleanup-demos`.
Schedule::command('argos:cleanup-demos')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Interactive workspaces: stop idle containers (volumes stay) and remove
// containers whose workspace is gone.
Schedule::command('argos:workspaces:idle-stop')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('argos:workspaces:reap-orphans')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('argos:workspaces:sync-prs')
    ->cron('*/'.max(1, (int) config('argos.workspaces.pr_sync_minutes', 15)).' * * * *')
    ->withoutOverlapping();

// Delete automatic workspaces (API/MCP/issue) once their pull request is
// merged/closed or their run ended ARGOS_AUTO_CLEANUP_DAYS ago.
Schedule::command('argos:workspaces:cleanup-auto')
    ->dailyAt('03:30')
    ->withoutOverlapping();
