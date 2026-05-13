<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Build a single ZIP that contains everything needed to reproduce a task
 * failure: DB rows, worker volume contents, host-side phase logs, and the
 * portion of argos.log that mentions this task. Tokens and credential
 * payloads are redacted before serialisation.
 *
 * Hard cap of 50 MB on the output ZIP — when reached, further files are
 * skipped and a note is appended to MANIFEST.txt.
 */
class TaskLogBundleBuilder
{
    private const MAX_BYTES = 52_428_800;

    /** @var list<string> */
    private const SENSITIVE_KEY_PATTERNS = [
        'token',
        'secret',
        'password',
        'authorization',
        'api_key',
        'apikey',
        'oauth',
        'credentials',
    ];

    /**
     * @return string absolute path to the temp ZIP file (caller deletes after send)
     */
    public function build(Task $task): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'argos-bundle-').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create bundle ZIP at '.$tmp);
        }

        $manifest = [];
        $bytesWritten = 0;
        $manifest[] = sprintf('Argos task bundle — %s', $task->name);
        $manifest[] = sprintf('Generated: %s', now()->toIso8601String());
        $manifest[] = '';

        // 1) task.json
        $taskJson = json_encode(
            $this->redact($task->load('repoProfile', 'agentCredential')->toArray()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->addStringEntry($zip, 'task.json', (string) $taskJson, $bytesWritten, $manifest);

        // 2) phase_runs.json — all runs, full payloads
        $runs = PhaseRun::where('task_id', $task->id)
            ->orderBy('phase')
            ->orderBy('iteration')
            ->get()
            ->map(fn (PhaseRun $r) => $this->redact($r->toArray()))
            ->all();
        $runsJson = json_encode($runs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->addStringEntry($zip, 'phase_runs.json', (string) $runsJson, $bytesWritten, $manifest);

        // 3) Worker volume contents (state.json, logs/, usage_limit.env, …)
        $volumeFiles = $this->readVolumeFiles($task);
        foreach ($volumeFiles as $entryName => $content) {
            if (! $this->addStringEntry($zip, "workspace/{$entryName}", $content, $bytesWritten, $manifest)) {
                break;
            }
        }

        // 4) Host-side phase bg.log files
        $configDir = (string) config('argos.config_dir');
        $taskLogDir = "{$configDir}/tasks/{$task->name}";
        if (is_dir($taskLogDir)) {
            foreach (glob("{$taskLogDir}/*.bg.log") ?: [] as $logFile) {
                $base = basename($logFile);
                $content = (string) @file_get_contents($logFile);
                if (! $this->addStringEntry($zip, "host-logs/{$base}", $content, $bytesWritten, $manifest)) {
                    break;
                }
            }
        }

        // 5) Filtered argos.log slice for this task
        $argosLogSlice = $this->extractArgosLogSlice($task);
        if ($argosLogSlice !== '') {
            $this->addStringEntry($zip, "argos.log.task-{$task->id}.txt", $argosLogSlice, $bytesWritten, $manifest);
        }

        // 6) MANIFEST.txt
        $manifest[] = '';
        $manifest[] = sprintf('Total entries: %d', $zip->numFiles + 1);
        $manifest[] = sprintf('Approximate uncompressed size: %d bytes', $bytesWritten);
        $zip->addFromString('MANIFEST.txt', implode("\n", $manifest)."\n");

        $zip->close();

        return $tmp;
    }

    /**
     * Add a file entry to the ZIP unless that would push us past MAX_BYTES.
     * Returns false when the cap was hit.
     */
    private function addStringEntry(ZipArchive $zip, string $name, string $content, int &$bytesWritten, array &$manifest): bool
    {
        $size = strlen($content);
        if ($bytesWritten + $size > self::MAX_BYTES) {
            $manifest[] = sprintf('SKIPPED %s (size %d B — would exceed %d B cap)', $name, $size, self::MAX_BYTES);

            return false;
        }
        $zip->addFromString($name, $content);
        $bytesWritten += $size;
        $manifest[] = sprintf('%9d  %s', $size, $name);

        return true;
    }

    /**
     * Pull state.json, runtime/usage_limit.env, logs/*.log, logs/*.result.json,
     * concept.md and the two implement summaries off the worker volume in one
     * shot. The shell script emits a delimited stream; we parse it back into
     * a path => content map.
     *
     * @return array<string, string>
     */
    protected function readVolumeFiles(Task $task): array
    {
        $output = $this->readVolumeStream($task);
        if ($output === '') {
            return [];
        }

        return $this->parseBundleStream($output);
    }

    /**
     * Spawn the read-only `docker run` that streams the workspace contents
     * back to us. Overridable in tests via partialMock — the docker call is
     * the one external dependency we want to fake.
     */
    protected function readVolumeStream(Task $task): string
    {
        $script = <<<'SH'
DIR=/workspace/.agent
emit() {
    [ -f "$1" ] || return 0
    size=$(wc -c < "$1")
    printf '###BUNDLE-FILE-START###%s###%s###\n' "$2" "$size"
    cat "$1"
    printf '\n###BUNDLE-FILE-END###\n'
}

emit "$DIR/state.json" "state.json"
emit "$DIR/runtime/usage_limit.env" "runtime/usage_limit.env"
emit "$DIR/concept.md" "concept.md"
emit "$DIR/implement.summary.nontechnical.md" "implement.summary.nontechnical.md"
emit "$DIR/implement.summary.technical.md" "implement.summary.technical.md"

if [ -d "$DIR/logs" ]; then
    for f in "$DIR/logs"/*; do
        [ -f "$f" ] || continue
        emit "$f" "logs/$(basename "$f")"
    done
fi
SH;

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            'alpine',
            'sh', '-c', $script,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('TaskLogBundleBuilder: docker run failed', ['error' => $e->getMessage()]);

            return '';
        }

        if (! $process->isSuccessful()) {
            return '';
        }

        return $process->getOutput();
    }

    /**
     * @return array<string, string>
     */
    private function parseBundleStream(string $output): array
    {
        $files = [];
        $pattern = '/###BUNDLE-FILE-START###([^#]+)###\d+###\r?\n(.*?)\r?\n###BUNDLE-FILE-END###/s';
        if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) === false) {
            return $files;
        }
        foreach ($matches as $m) {
            $name = trim($m[1]);
            if ($name !== '') {
                $files[$name] = $m[2];
            }
        }

        return $files;
    }

    /**
     * Recursively replace values of keys that look credential-shaped with a
     * fixed redaction marker. Operates on already-decoded arrays so it works
     * for Model::toArray() output and decoded JSON alike.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }
            if ($this->isSensitiveKey((string) $key) && $value !== null && $value !== '') {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lowered = strtolower($key);
        foreach (self::SENSITIVE_KEY_PATTERNS as $needle) {
            if (str_contains($lowered, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractArgosLogSlice(Task $task): string
    {
        $logPath = storage_path('logs/argos.log');
        if (! file_exists($logPath)) {
            return '';
        }

        $needles = [$task->id, $task->name];
        $fh = fopen($logPath, 'r');
        if ($fh === false) {
            return '';
        }
        $out = '';
        while (! feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            foreach ($needles as $n) {
                if ($n !== '' && str_contains($line, $n)) {
                    $out .= $line;
                    break;
                }
            }
            if (strlen($out) > 5_000_000) {
                $out .= "\n... [truncated at 5 MB] ...\n";
                break;
            }
        }
        fclose($fh);

        return $out;
    }
}
