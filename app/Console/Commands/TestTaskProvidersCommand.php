<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Phase;
use App\Enums\TaskProviderKind;
use App\Enums\WorkflowStatus;
use App\Jobs\PollIssueProviderJob;
use App\Models\ConnectedAccount;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\Task\TaskService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;

#[Signature('test:task-providers
    {--label= : Demo label to match on (default: argos.provider_demo.label)}
    {--writeback-timeout=15 : Minutes to wait for the write-back phase to finish}')]
#[Description('Interactive end-to-end runner for the issue-provider integration: seeds the demo bindings, then per provider checks webhook + tag matching (automated), real poll (guided), and write-back (guided + auto-check).')]
final class TestTaskProvidersCommand extends Command
{
    /** @var list<array{kind: string, label: string, redirect: string, profile: string, target: string}> */
    private const PROVIDERS = [
        ['kind' => 'github', 'label' => 'GitHub', 'redirect' => '/auth/github/redirect', 'profile' => 'provider-demo (github)', 'target' => 'repository'],
        ['kind' => 'gitlab', 'label' => 'GitLab', 'redirect' => '/auth/gitlab/redirect', 'profile' => 'provider-demo (gitlab)', 'target' => 'repository (gitlab.com account — the demo repo is on cloud GitLab)'],
        ['kind' => 'linear', 'label' => 'Linear', 'redirect' => '/auth/linear/redirect', 'profile' => 'provider-demo (bitbucket)', 'target' => 'team'],
    ];

    public function handle(): int
    {
        $user = User::where('email', (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local'))->first()
            ?? User::orderBy('id')->first();

        if ($user === null) {
            $this->error('No user found — run DemoSeeder first.');

            return self::FAILURE;
        }

        $label = (string) ($this->option('label') ?: config('argos.provider_demo.label', 'argos-demo'));

        $this->info('═══ Setup: seeding demo bindings ═══');
        Artisan::call('db:seed', ['--class' => 'ProviderDemoSeeder', '--force' => true], $this->output);

        /** @var list<array{provider: string, webhook: string, account: string, poll: string}> $results */
        $results = [];
        /** @var array{task: Task, binding: TaskProviderBinding, kind: TaskProviderKind}|null $importedForWriteBack */
        $importedForWriteBack = null;

        foreach (self::PROVIDERS as $p) {
            $kind = TaskProviderKind::from($p['kind']);
            $this->newLine();
            $this->info("═══ {$p['label']} ═══");

            $webhookBinding = $this->resolveBinding($kind, 'webhook');
            $pollBinding = $this->resolveBinding($kind, 'poll');

            if ($webhookBinding === null && $pollBinding === null) {
                $this->warn('  No demo bindings found — did ProviderDemoSeeder run? Skipping.');
                $results[] = ['provider' => $p['label'], 'webhook' => 'no binding', 'account' => '—', 'poll' => '—'];

                continue;
            }

            // 1) Webhook + tag matching — automated, no account needed.
            $webhookResult = $webhookBinding !== null
                ? ($this->checkWebhook($webhookBinding, $label) ? '✓ pass' : '✗ fail')
                : 'no binding';

            // 2) Account — required for the real poll and write-back.
            $account = $this->ensureAccount($user, $p, $kind);
            $accountResult = $account !== null ? '✓ linked' : '✗ missing';

            // 3) Real poll — guided.
            $pollResult = 'skipped';
            if ($account !== null && $pollBinding !== null
                && $this->confirm("  Run a real poll for {$p['label']}? (creates a Task from a real issue)", false)) {
                $task = $this->checkPoll($pollBinding, $label, $p);
                $pollResult = $task !== null ? '✓ task created' : '✗ no task';
                if ($task !== null && $importedForWriteBack === null) {
                    $importedForWriteBack = ['task' => $task, 'binding' => $pollBinding, 'kind' => $kind];
                }
            }

            $results[] = [
                'provider' => $p['label'],
                'webhook' => $webhookResult,
                'account' => $accountResult,
                'poll' => $pollResult,
            ];
        }

        // 4) Write-back — once, guided + auto-check (starts a real phase).
        $writeBack = $this->maybeCheckWriteBack($importedForWriteBack);

        $this->newLine();
        $this->info('═══ Summary ═══');
        $this->table(['Provider', 'Webhook + Tags', 'Account', 'Poll'], array_map(
            fn (array $r): array => [$r['provider'], $r['webhook'], $r['account'], $r['poll']],
            $results,
        ));
        $this->line("Write-back: {$writeBack}");

        return self::SUCCESS;
    }

    private function resolveBinding(TaskProviderKind $kind, string $mode): ?TaskProviderBinding
    {
        return TaskProviderBinding::where('kind', $kind->value)
            ->where('mode', $mode)
            ->whereHas('repoProfile', fn ($q) => $q->where('name', 'like', 'provider-demo%'))
            ->first();
    }

    /**
     * Fire a signed fake webhook with a matching label (expect a Task) and with
     * a non-matching label (expect it filtered out), and verify both.
     */
    private function checkWebhook(TaskProviderBinding $binding, string $label): bool
    {
        $matchId = 'tp-match-'.bin2hex(random_bytes(4));
        $noMatchId = 'tp-nomatch-'.bin2hex(random_bytes(4));

        // Task names are unique; keep titles distinct per provider and per run.
        Artisan::call('argos:webhook:simulate', [
            'binding' => $binding->id,
            '--label' => [$label],
            '--id' => $matchId,
            '--title' => "test:task-providers — {$binding->kind->value} import {$matchId}",
        ]);
        Artisan::call('argos:webhook:simulate', [
            'binding' => $binding->id,
            '--label' => ['tp-unrelated-label'],
            '--id' => $noMatchId,
            '--title' => "test:task-providers — {$binding->kind->value} filtered {$noMatchId}",
        ]);

        $matched = $this->linkHasTask($binding, $matchId, true);
        $filtered = $this->linkHasTask($binding, $noMatchId, false);

        $this->line($matched ? '  ✓ matching label imported a Task' : '  ✗ matching label did NOT import a Task');
        $this->line($filtered ? '  ✓ non-matching label was filtered out' : '  ✗ non-matching label was NOT filtered');

        return $matched && $filtered;
    }

    /**
     * Poll briefly (queue may be async) for the link of $externalId and report
     * whether its task presence matches expectation.
     */
    private function linkHasTask(TaskProviderBinding $binding, string $externalId, bool $expectTask): bool
    {
        for ($i = 0; $i < 15; $i++) {
            $link = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
                ->where('external_id', $externalId)
                ->first();

            if ($link !== null) {
                return ($link->task_id !== null) === $expectTask;
            }

            usleep(200_000);
        }

        // No link at all → only "correct" when we did not expect a task.
        return ! $expectTask;
    }

    /**
     * @param  array{kind: string, label: string, redirect: string, profile: string, target: string}  $p
     */
    private function ensureAccount(User $user, array $p, TaskProviderKind $kind): ?ConnectedAccount
    {
        $account = $user->connectedAccount($p['kind']);
        if ($account !== null) {
            $this->line("  ✓ {$p['label']} account connected.");

            return $account;
        }

        $base = rtrim((string) (config('app.url') ?: 'http://localhost'), '/');
        $this->warn("  No {$p['label']} account connected — needed for the real poll / write-back.");
        $this->line("    Connect it: {$base}{$p['redirect']}");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (! $this->confirm("  Connected the {$p['label']} account and want to re-check?", true)) {
                $this->line('  → skipping poll / write-back for this provider.');

                return null;
            }

            $account = $user->fresh()?->connectedAccount($p['kind']);
            if ($account !== null) {
                $this->line("  ✓ {$p['label']} account connected.");

                return $account;
            }

            $this->comment('  Still not connected.');
        }

        $this->line('  → giving up on this provider for now.');

        return null;
    }

    /**
     * @param  array{kind: string, label: string, redirect: string, profile: string, target: string}  $p
     */
    private function checkPoll(TaskProviderBinding $binding, string $label, array $p): ?Task
    {
        $ref = (string) $binding->external_project_ref;
        $this->line("  In the {$p['target']} '{$ref}', create an issue and add the label '{$label}'.");

        while (! $this->confirm('  Issue created — poll now?', true)) {
            $this->comment('  Waiting…');
        }

        $before = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
            ->whereNotNull('task_id')->count();

        try {
            PollIssueProviderJob::dispatchSync($binding->id);
        } catch (\Throwable $e) {
            $this->error('  Poll failed: '.$e->getMessage());

            return null;
        }

        $after = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
            ->whereNotNull('task_id')->latest('id')->first();

        $count = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
            ->whereNotNull('task_id')->count();

        if ($count > $before && $after?->task !== null) {
            $this->line("  ✓ poll imported Task #{$after->task_id}.");

            return $after->task;
        }

        $this->warn('  No new Task from the poll. Is the issue open, correctly labelled, and the account on the right host?');

        return null;
    }

    /**
     * @param  array{task: Task, binding: TaskProviderBinding, kind: TaskProviderKind}|null  $imported
     */
    private function maybeCheckWriteBack(?array $imported): string
    {
        if ($imported === null) {
            return 'skipped (no imported task — run a poll first)';
        }

        $this->newLine();
        $this->info('═══ Write-back ═══');
        if (! $this->confirm('  Start a real phase on the imported task to test write-back? (costs worker tokens)', false)) {
            return 'skipped';
        }

        $task = $imported['task'];
        app(TaskService::class)->startPhase($task, Phase::Concept);
        $this->line('  Concept phase started — waiting for it to finish…');

        $timeoutMinutes = (int) $this->option('writeback-timeout');
        $deadline = $timeoutMinutes * 60;
        $running = [WorkflowStatus::ConceptRunning->value, WorkflowStatus::ImplementRunning->value];

        for ($waited = 0; $waited < $deadline; $waited += 10) {
            sleep(10);
            $status = $task->fresh()?->workflow_status?->value;
            if ($status !== null && ! in_array($status, $running, true)) {
                break;
            }
        }

        return $this->verifyComment($imported);
    }

    /**
     * @param  array{task: Task, binding: TaskProviderBinding, kind: TaskProviderKind}  $imported
     */
    private function verifyComment(array $imported): string
    {
        $binding = $imported['binding'];
        $link = ExternalIssueLink::where('task_id', $imported['task']->id)->first();
        if ($link === null) {
            return 'could not verify (no issue link)';
        }

        [$owner, $project] = array_pad(explode('/', (string) $binding->external_project_ref, 2), 2, '');

        try {
            $tracker = app(IssueTrackerRegistry::class)->make($imported['kind'], $binding);
            $issue = $tracker->getIssue($owner, $project, (string) $link->external_id);
            $comments = is_array($issue['comments_data'] ?? null) ? $issue['comments_data'] : [];

            foreach ($comments as $comment) {
                if (is_array($comment) && str_contains((string) ($comment['body'] ?? ''), 'Argos')) {
                    return '✓ comment posted on the issue';
                }
            }

            return '✗ no Argos comment found yet — check the issue manually';
        } catch (\Throwable $e) {
            return 'could not verify automatically ('.$e->getMessage().') — check the issue manually';
        }
    }
}
