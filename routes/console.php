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

// Every 5 minutes: dispatch PollIssueProviderJob for each active Poll binding.
// Manual trigger: `php artisan argos:poll-issues`.
Schedule::command('argos:poll-issues')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Every 5 minutes: check concept comments for an authorized 👍 reaction and
// start implement when present (providers don't push reaction events, so we
// poll). Manual trigger: `php artisan argos:check-concept-approvals`.
Schedule::command('argos:check-concept-approvals')
    ->everyFiveMinutes()
    ->withoutOverlapping();
