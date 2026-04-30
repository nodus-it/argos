<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Domain\Task\TaskService;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\warning;

class ArgosCommand extends Command
{
    protected $signature = 'argos';

    protected $description = 'Interaktive TUI für Argos Task-Management';

    public function __construct(
        private readonly PhaseRunner $phaseRunner,
        private readonly StateReader $stateReader,
    ) {
        parent::__construct();
    }

    public function handle(TaskService $taskService): int
    {
        while (true) {
            system('clear');
            $this->renderHeader();
            $this->line('');

            $tasks = $taskService->list();
            $this->renderTaskTable($tasks);
            $this->line('');

            $options = [];
            foreach ($tasks as $task) {
                $phase = $task->current_phase ?? '—';
                $status = $task->current_status ?? '·';
                $options["task:{$task->name}"] = "{$task->name}  {$phase}  {$status}";
            }
            $options['---new'] = '+ Neuer Task';
            $options['---refresh'] = '↺ Aktualisieren';
            $options['---quit'] = '✕ Beenden';

            $choice = select('Aktion', $options);

            if (str_starts_with((string) $choice, 'task:')) {
                $taskName = substr((string) $choice, 5);
                $this->taskMenu($taskName, $taskService);
            } elseif ($choice === '---new') {
                $this->newTaskWizard($taskService);
            } elseif ($choice === '---refresh') {
                continue;
            } elseif ($choice === '---quit') {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function taskMenu(string $taskName, TaskService $taskService): void
    {
        while (true) {
            system('clear');

            $task = $taskService->find($taskName);

            if ($task === null) {
                error("Task '{$taskName}' nicht gefunden.");
                return;
            }

            $this->renderTaskDetail($task);
            $this->line('');

            $choice = select('Aktion', [
                'concept'   => '● Concept generieren',
                'implement' => '● Implement',
                'diff'      => '● Diff anzeigen',
                'push'      => '● Push + PR',
                'feedback'  => '✎ Feedback schreiben',
                'back'      => '← Zurück',
            ]);

            match ($choice) {
                'concept'   => $this->runPhase($task, 'concept'),
                'implement' => $this->runPhase($task, 'implement'),
                'diff'      => $this->showDiff($task),
                'push'      => $this->runPhase($task, 'push'),
                'feedback'  => $this->writeFeedback($task),
                'back'      => null,
            };

            if ($choice === 'back') {
                return;
            }
        }
    }

    private function runPhase(Task $task, string $phase): void
    {
        system('clear');
        info("▶ Phase: {$phase} — {$task->name}");
        $this->line('');

        try {
            foreach ($this->phaseRunner->run($task, $phase) as $chunk) {
                echo $chunk;
            }
        } catch (\RuntimeException $e) {
            $this->line('');
            error($e->getMessage());
            sleep(2);
            return;
        }

        $this->line('');
        info('Phase abgeschlossen. [Enter] zum Fortfahren');
        readline();
    }

    private function showDiff(Task $task): void
    {
        system('clear');
        info("Diff — {$task->name}");
        $this->line('');

        $branch = $task->repoProfile?->default_branch ?? 'main';

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$task->name}:/workspace",
            '--entrypoint', 'sh',
            'agent-worker:latest',
            '-c', "git -C /workspace diff origin/{$branch} HEAD --stat",
        ]);

        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            $this->line($output !== '' ? $output : '(Kein Diff)');
        } else {
            warning('Diff konnte nicht geladen werden.');
            $this->line($process->getErrorOutput());
        }

        $this->line('');
        info('[Enter] zum Fortfahren');
        readline();
    }

    private function writeFeedback(Task $task): void
    {
        $existing = $this->stateReader->readNotes($task->name);

        $notes = textarea('Feedback / Notes', default: $existing ?? '');

        if ($notes === $existing) {
            return;
        }

        $process = new Process([
            'docker', 'run', '--rm',
            '-i',
            '-v', "task_ws_{$task->name}:/workspace",
            '--entrypoint', 'sh',
            'agent-worker:latest',
            '-c', 'mkdir -p /workspace/.agent && cat > /workspace/.agent/concept.notes.md',
        ]);

        $process->setInput($notes);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            info('Feedback gespeichert.');
        } else {
            error('Feedback konnte nicht gespeichert werden.');
        }

        sleep(1);
    }

    private function newTaskWizard(TaskService $taskService): void
    {
        system('clear');
        info('Neuer Task');
        $this->line('');

        // Repo-Profil wählen oder neu anlegen
        $profiles = RepoProfile::all();

        if ($profiles->isEmpty()) {
            $profile = $this->createRepoProfile();
        } else {
            $options = $profiles->pluck('name', 'id')->toArray();
            $options['---new'] = '+ Neues Profil anlegen';

            $selected = select('Repo-Profil', $options);

            if ($selected === '---new') {
                $profile = $this->createRepoProfile();
            } else {
                $profile = RepoProfile::find($selected);
            }
        }

        if ($profile === null) {
            error('Kein Repo-Profil ausgewählt.');
            return;
        }

        $this->line('');

        // Task-Name
        $name = text(
            'Task-Name (z.B. fix-auth-bug)',
            validate: fn (string $v) => preg_match('/^[a-z0-9][a-z0-9\-]{1,48}[a-z0-9]$/', $v)
                ? null
                : 'Nur Kleinbuchstaben, Zahlen, Bindestriche (3-50 Zeichen)',
        );

        // Description
        $description = textarea('Task-Beschreibung (Markdown)', rows: 10);

        // Task in DB anlegen
        $task = $taskService->create([
            'name' => $name,
            'repo_profile_id' => $profile->id,
            'description' => $description,
        ]);

        // description.md auf Host für Docker-Mount ablegen
        $configDir = config('argos.config_dir');
        $taskDir = "{$configDir}/tasks/{$name}";

        if (!is_dir($taskDir)) {
            mkdir($taskDir, 0755, true);
        }

        file_put_contents("{$taskDir}/description.md", $description);

        // Docker Volume anlegen
        Process::fromShellCommandline("docker volume create task_ws_{$name}")->run();

        $this->line('');
        info("Task '{$name}' angelegt.");

        if (confirm('Concept jetzt generieren?', default: false)) {
            $this->taskMenu($name, app(TaskService::class));
        }
    }

    private function createRepoProfile(): ?RepoProfile
    {
        $this->line('');
        info('Neues Repo-Profil');
        $this->line('');

        $name     = text('Profil-Name (z.B. nodus-it/test)');
        $url      = text('Repo-URL (https://...)');
        $token    = password('Token (ghp_... oder GitLab PAT)');
        $branch   = text('Default Branch', default: 'main');
        $platform = select('Platform', ['github' => 'GitHub', 'gitlab' => 'GitLab']);

        return RepoProfile::create([
            'name'           => $name,
            'url'            => $url,
            'token'          => $token,
            'default_branch' => $branch,
            'platform'       => $platform,
        ]);
    }

    private function renderHeader(): void
    {
        $db   = config('database.default') === 'mariadb' ? '⬡ MariaDB' : '◌ SQLite';
        $time = now()->format('H:i');
        $this->line("┌─ ARGOS ──────────────────────── {$db}  {$time} ─┐");
    }

    private function renderTaskTable(Collection $tasks): void
    {
        if ($tasks->isEmpty()) {
            $this->line('  (Keine Tasks vorhanden)');
            return;
        }

        $this->line(sprintf(
            '  %-30s %-12s %-22s %-20s',
            'Name',
            'Phase',
            'Status',
            'Repo-Profil',
        ));
        $this->line('  ' . str_repeat('─', 86));

        foreach ($tasks as $task) {
            $this->line(sprintf(
                '  %-30s %-12s %-22s %-20s',
                $task->name,
                $task->current_phase ?? '—',
                $task->current_status ?? '·',
                $task->repoProfile?->name ?? '—',
            ));
        }
    }

    private function renderTaskDetail(Task $task): void
    {
        $this->line("┌─ Task: {$task->name} " . str_repeat('─', max(0, 50 - strlen($task->name))) . '┐');
        $this->line('│  Repo-Profil : ' . ($task->repoProfile?->name ?? '—'));
        $this->line('│  Branch      : ' . ($task->feature_branch ?? '—'));
        $this->line('│  PR-URL      : ' . ($task->pr_url ?? '—'));
        $this->line('└' . str_repeat('─', 60) . '┘');

        $runs = $task->phaseRuns()
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        if ($runs->isEmpty()) {
            $this->line('  (Noch keine Phase-Runs)');
            return;
        }

        $this->line('');
        $this->line(sprintf(
            '  %-12s %-6s %-22s %-10s %-20s',
            'Phase',
            'Iter.',
            'Status',
            'Kosten',
            'Gestartet',
        ));
        $this->line('  ' . str_repeat('─', 72));

        foreach ($runs as $run) {
            $cost = $run->cost_usd !== null ? '$' . number_format((float) $run->cost_usd, 4) : '—';
            $this->line(sprintf(
                '  %-12s %-6s %-22s %-10s %-20s',
                $run->phase,
                $run->iteration,
                $run->status,
                $cost,
                $run->started_at?->format('Y-m-d H:i:s') ?? '—',
            ));
        }
    }
}
