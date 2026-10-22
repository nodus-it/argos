<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

// New comments on the tickets of open tasks become hints in the chat.
Schedule::command('argos:tasks:sync-ticket-comments')
    ->cron('*/'.max(1, (int) config('argos.poll_interval_minutes', 5)).' * * * *')
    ->withoutOverlapping();

Schedule::command('argos:tasks:sync-prs')
    ->cron('*/'.max(1, (int) config('argos.workspaces.pr_sync_minutes', 3)).' * * * *')
    ->withoutOverlapping();

// Free preview, worktree and database of tasks that finished more than their
// workspace's cleanup_after_days ago (the conversation stays).
Schedule::command('argos:tasks:cleanup')
    ->dailyAt('03:30')
    ->withoutOverlapping();
