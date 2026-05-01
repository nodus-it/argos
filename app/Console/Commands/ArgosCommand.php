<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Domain\Task\TaskService;
use App\Jobs\RunPhaseJob;
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
                $icon = $status === 'running' ? '⟳' : ($status === 'completed' ? '✓' : '·');
                $options["task:{$task->name}"] = "{$icon} {$task->name}  {$phase}  {$status}";
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

            // Sync completed background phases back into the DB
            $this->stateReader->syncToDb($task);
            $task->refresh();

            $this->renderTaskDetail($task);
            $this->line('');

            $runningRun = $task->phaseRuns()
                ->where('status', 'running')
                ->latest('started_at')
                ->first();

            $options = [];

            if ($runningRun !== null) {
                $options['watch'] = "⟳ Ausgabe beobachten ({$runningRun->phase})";
                $options['refresh'] = '↺ Status aktualisieren';
            }

            $options['concept'] = '● Concept generieren';
            $options['implement'] = '● Implement starten';
            $options['view'] = '📄 Konzept anschauen + Feedback';
            $options['diff'] = '● Diff anzeigen';
            $options['push'] = '● Push + PR';
            $options['logs'] = '📋 Logs anzeigen';
            $options['back'] = '← Zurück';

            $choice = select('Aktion', $options);

            if ($choice === 'back') {
                return;
            }

            match ($choice) {
                'watch' => $this->watchPhase($task, $runningRun->phase),
                'refresh' => null,
                'concept' => $this->startPhaseBackground($task, 'concept'),
                'implement' => $this->startPhaseBackground($task, 'implement'),
                'view' => $this->showConceptAndFeedback($task),
                'diff' => $this->showDiff($task),
                'push' => $this->startPhaseBackground($task, 'push'),
                'logs' => $this->showLogs($task),
                default => null,
            };
        }
    }

    private function startPhaseBackground(Task $task, string $phase): void
    {
        $running = $task->phaseRuns()->where('status', 'running')->exists();
        if ($running) {
            warning('Eine Phase läuft bereits. Bitte erst beobachten oder warten bis sie abgeschlossen ist.');
            sleep(2);

            return;
        }

        try {
            RunPhaseJob::dispatch($task->id, $phase);
            info("Phase '{$phase}' wurde in die Queue eingereiht. ⟳");
        } catch (\RuntimeException $e) {
            error($e->getMessage());
        }

        sleep(1);
    }

    private function watchPhase(Task $task, string $phase): void
    {
        system('clear');
        info("⟳ Beobachte Phase: {$phase} — {$task->name}");
        $this->line('  (Endet automatisch wenn die Phase fertig ist)');
        $this->line('');

        $logPath = $this->phaseRunner->getPhaseLogPath($task->name, $phase);

        if (! is_file($logPath)) {
            warning("Log-Datei nicht gefunden: {$logPath}");
            sleep(2);

            return;
        }

        $offset = 0;
        $finalStatus = 'unknown';

        while (true) {
            // Stream new bytes from log
            $content = file_get_contents($logPath, false, null, $offset);
            if ($content !== false && $content !== '') {
                echo $content;
                $offset += strlen($content);
            }

            // Check state.json every 2 seconds for completion
            $state = $this->stateReader->read($task->name);
            $phaseStatus = $state['phases'][$phase]['current_status'] ?? 'running';

            if ($phaseStatus !== 'running') {
                // Drain any final bytes
                $remaining = file_get_contents($logPath, false, null, $offset);
                if ($remaining !== false && $remaining !== '') {
                    echo $remaining;
                }
                $finalStatus = $phaseStatus;
                break;
            }

            usleep(500_000);
        }

        $this->line('');
        $this->line("─── Phase '{$phase}' abgeschlossen: {$finalStatus} ───");
        $this->line('');
        info('[Enter] zum Fortfahren');
        readline();
    }

    private function showLogs(Task $task): void
    {
        $configDir = config('argos.config_dir');
        $phases = ['concept', 'implement', 'push', 'commit-message'];

        // Nur Phasen anbieten für die ein Background-Log existiert
        $options = [];
        foreach ($phases as $phase) {
            $path = $this->phaseRunner->getPhaseLogPath($task->name, $phase);
            if (is_file($path) && filesize($path) > 0) {
                $options[$phase] = $phase.'  ('.$this->formatBytes(filesize($path)).')';
            }
        }

        // Auch Logs direkt aus dem Volume anbieten
        $options['volume'] = '📦 Workspace-Logs (aus Volume)';
        $options['cancel'] = '← Zurück';

        system('clear');
        info("📋 Logs — {$task->name}");
        $this->line('');

        $choice = select('Welchen Log anzeigen?', $options);

        if ($choice === 'cancel') {
            return;
        }

        system('clear');

        if ($choice === 'volume') {
            $this->showVolumeLogs($task);

            return;
        }

        $logPath = $this->phaseRunner->getPhaseLogPath($task->name, $choice);
        info("Log: {$choice}");
        $this->line('');
        $this->line(file_get_contents($logPath) ?: '(leer)');
        $this->line('');
        info('[Enter] zum Fortfahren');
        readline();
    }

    private function showVolumeLogs(Task $task): void
    {
        // Verfügbare Log-Dateien im Volume auflisten
        $listProcess = new Process([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            'alpine',
            'sh', '-c', 'ls -lh /workspace/.agent/logs/ 2>/dev/null | tail -30',
        ]);
        $listProcess->setTimeout(10);
        $listProcess->run();

        info("Workspace-Logs — {$task->name}");
        $this->line('');
        $this->line($listProcess->getOutput() ?: '(keine Logs vorhanden)');
        $this->line('');

        // Dateiname abfragen
        $filename = text('Dateiname anzeigen (z.B. push.1.log, leer = abbrechen)');
        if ($filename === '') {
            return;
        }

        $catProcess = new Process([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            'alpine',
            'cat', "/workspace/.agent/logs/{$filename}",
        ]);
        $catProcess->setTimeout(10);
        $catProcess->run();

        $this->line('');
        $this->line($catProcess->isSuccessful() ? $catProcess->getOutput() : "Datei nicht gefunden: {$filename}");
        $this->line('');
        info('[Enter] zum Fortfahren');
        readline();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        return round($bytes / 1024, 1).' KB';
    }

    private function showDiff(Task $task): void
    {
        system('clear');
        info("Diff — {$task->name}");
        $this->line('');

        $branch = $task->repoProfile?->default_branch ?? 'main';

        // Show committed changes vs origin AND uncommitted working-tree changes
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            '--entrypoint', 'sh',
            'agent-worker:latest',
            '-c', implode(' && ', [
                "echo '=== Committed vs origin/{$branch} ==='",
                "git -C /workspace diff --stat origin/{$branch}...HEAD 2>/dev/null || echo '(kein origin-Ref)'",
                "echo ''",
                "echo '=== Uncommitted changes (working tree) ==='",
                "git -C /workspace status --short 2>/dev/null || echo '(kein git repo)'",
            ]),
        ]);

        $process->setTimeout(30);
        $process->run();

        $output = trim($process->getOutput());
        $this->line($output !== '' ? $output : '(Keine Änderungen erkannt)');

        $this->line('');
        info('[Enter] zum Fortfahren');
        readline();
    }

    private function showConceptAndFeedback(Task $task): void
    {
        system('clear');
        info("Konzept — {$task->name}");
        $this->line('');

        // Read concept.md from volume
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            'alpine',
            'cat', '/workspace/.agent/concept.md',
        ]);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful() && $process->getOutput() !== '') {
            $this->line($process->getOutput());
        } else {
            $this->line('  (Noch kein Konzept vorhanden — bitte zuerst Concept-Phase starten)');
        }

        $this->line('');
        $this->line(str_repeat('─', 60));
        $this->line('');

        $existing = $task->concept_notes;

        if ($existing !== null) {
            $this->line('Aktuelles Feedback:');
            $this->line($existing);
            $this->line('');
        }

        $notes = textarea(
            'Feedback / Notes für nächste Concept-Iteration',
            hint: 'Leer lassen und Enter drücken um nichts zu ändern',
            default: $existing ?? '',
        );

        if ($notes === $existing || $notes === '') {
            return;
        }

        $task->update(['concept_notes' => $notes ?: null]);
        info('Feedback gespeichert. Starte jetzt Concept neu um es einzuarbeiten.');

        sleep(2);
    }

    private function newTaskWizard(TaskService $taskService): void
    {
        system('clear');
        info('Neuer Task');
        $this->line('');

        $profiles = RepoProfile::all();

        if ($profiles->isEmpty()) {
            $profile = $this->createRepoProfile();
        } else {
            $options = $profiles->pluck('name', 'id')->toArray();
            $options['---new'] = '+ Neues Profil anlegen';

            $selected = select('Repo-Profil', $options);

            $profile = $selected === '---new'
                ? $this->createRepoProfile()
                : RepoProfile::find($selected);
        }

        if ($profile === null) {
            error('Kein Repo-Profil ausgewählt.');

            return;
        }

        $this->line('');

        $name = text(
            'Task-Name (z.B. fix-auth-bug)',
            validate: fn (string $v) => preg_match('/^[a-z0-9][a-z0-9\-]{1,48}[a-z0-9]$/', $v)
                ? null
                : 'Nur Kleinbuchstaben, Zahlen, Bindestriche (3-50 Zeichen)',
        );

        $description = textarea('Task-Beschreibung (Markdown)', rows: 10);

        $task = $taskService->create([
            'name' => $name,
            'repo_profile_id' => $profile->id,
            'description' => $description,
        ]);

        Process::fromShellCommandline('docker volume create '.Task::slugifyName($name))->run();

        $this->line('');
        info("Task '{$name}' angelegt.");

        if (confirm('Concept jetzt generieren?', default: false)) {
            $this->startPhaseBackground($task, 'concept');
            $this->taskMenu($name, app(TaskService::class));
        }
    }

    private function createRepoProfile(): ?RepoProfile
    {
        $this->line('');
        info('Neues Repo-Profil');
        $this->line('');

        $name = text('Profil-Name (z.B. nodus-it/test)');
        $url = text('Repo-URL (https://...)');
        $token = password('Token (ghp_... oder GitLab PAT)');
        $branch = text('Default Branch', default: 'main');
        $platform = select('Platform', ['github' => 'GitHub', 'gitlab' => 'GitLab']);

        return RepoProfile::create([
            'name' => $name,
            'url' => $url,
            'token' => $token,
            'default_branch' => $branch,
            'platform' => $platform,
        ]);
    }

    private function renderHeader(): void
    {
        $db = config('database.default') === 'mariadb' ? '⬡ MariaDB' : '◌ SQLite';
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
            'Name', 'Phase', 'Status', 'Repo-Profil',
        ));
        $this->line('  '.str_repeat('─', 86));

        foreach ($tasks as $task) {
            $status = $task->current_status ?? '·';
            $icon = match ($status) {
                'running' => '⟳',
                'completed' => '✓',
                'failed' => '✗',
                'quality_gate_failed' => '!',
                'no_changes' => '=',
                default => '·',
            };
            $this->line(sprintf(
                '  %-30s %-12s %-22s %-20s',
                $task->name,
                $task->current_phase ?? '—',
                "{$icon} {$status}",
                $task->repoProfile?->name ?? '—',
            ));
        }
    }

    private function renderTaskDetail(Task $task): void
    {
        $this->line('┌─ Task: '.$task->name.' '.str_repeat('─', max(0, 50 - strlen($task->name))).'┐');
        $this->line('│  Repo-Profil : '.($task->repoProfile?->name ?? '—'));
        $this->line('│  Branch      : '.($task->feature_branch ?? '—'));
        $this->line('│  PR-URL      : '.($task->pr_url ?? '—'));
        $this->line('└'.str_repeat('─', 60).'┘');

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
            'Phase', 'Iter.', 'Status', 'Kosten', 'Gestartet',
        ));
        $this->line('  '.str_repeat('─', 72));

        foreach ($runs as $run) {
            $cost = $run->cost_usd !== null ? '$'.number_format((float) $run->cost_usd, 4) : '—';
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
