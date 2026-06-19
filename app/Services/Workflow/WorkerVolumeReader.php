<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use Symfony\Component\Process\Process;

/**
 * Reads files and quality-gate logs out of a task's worker volume by shelling
 * into a throwaway `alpine` container mounted read-only on the volume. Split
 * out of PhaseRunner so the Docker-volume access is one cohesive concern.
 */
class WorkerVolumeReader
{
    /**
     * Quality-gate log file names emitted by worker/lib/quality.sh per iteration.
     * Key = gate slug used in PhaseRun.quality_gate_logs / UI.
     * Value = log filename base in /workspace/.agent/logs/.
     *
     * @var array<string, string>
     */
    private const QUALITY_GATE_LOG_BASES = [
        'artisan' => 'artisan-smoke',
        'pint' => 'pint',
        'pest' => 'pest',
        'phpunit' => 'phpunit',
        'phpstan' => 'phpstan',
        'migrations' => 'migrations',
        'debug_code' => 'debug-code',
    ];

    public function readFile(string $volumeName, string $filePath): ?string
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', "{$volumeName}:/workspace:ro",
            'alpine',
            'cat', $filePath,
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    /**
     * Read all quality-gate logs for one phase iteration from the worker volume.
     * Each gate may have one initial log and up to three fix-session logs
     * (suffixes .fixN). Files larger than ~200KB are head+tail-truncated so
     * the DB row stays reasonable while still showing the failure summary
     * (which Pest/PHPStan emit at the end of their output).
     *
     * @return array<string, string>|null keyed by gate slug (e.g. "pest", "pest.fix1")
     */
    public function readQualityGateLogs(string $volumeName, int $iteration): ?array
    {
        $script = $this->buildGateLogReadScript();
        $iterationArg = (string) $iteration;

        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', "{$volumeName}:/workspace:ro",
            'alpine',
            'sh', '-c', $script, 'gate-log-reader', $iterationArg,
        ]);
        $process->setTimeout(20);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        return $this->parseGateLogOutput($output);
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }

    /**
     * Shell script (busybox-compatible) that walks the known quality-gate
     * log files for an iteration and emits them with delimiters. Each block
     * is `###GATE-LOG-START###<key>###<size>###` ... `###GATE-LOG-END###`.
     * Truncation is applied byte-wise (head+tail with a marker in between).
     */
    private function buildGateLogReadScript(): string
    {
        $gates = array_values(self::QUALITY_GATE_LOG_BASES);
        $gateList = implode(' ', array_map(fn (string $g): string => escapeshellarg($g), $gates));

        return <<<SH
ITER="\$1"
DIR=/workspace/.agent/logs
MAX_BYTES=204800
HEAD_BYTES=51200
TAIL_BYTES=153600

[ -d "\$DIR" ] || exit 0

emit_file() {
    f="\$1"
    key="\$2"
    [ -f "\$f" ] || return 0
    size=\$(wc -c < "\$f")
    printf '###GATE-LOG-START###%s###%s###\\n' "\$key" "\$size"
    if [ "\$size" -le "\$MAX_BYTES" ]; then
        cat "\$f"
    else
        head -c "\$HEAD_BYTES" "\$f"
        printf '\\n\\n... [%s bytes ausgelassen — Log gekürzt: erste %s + letzte %s bytes] ...\\n\\n' \\
            "\$((size - HEAD_BYTES - TAIL_BYTES))" "\$HEAD_BYTES" "\$TAIL_BYTES"
        tail -c "\$TAIL_BYTES" "\$f"
    fi
    printf '\\n###GATE-LOG-END###\\n'
}

for gate in {$gateList}; do
    emit_file "\$DIR/\$gate.\$ITER.log" "\$gate"
    for n in 1 2 3 4 5; do
        emit_file "\$DIR/\$gate.\$ITER.fix\$n.log" "\$gate.fix\$n"
    done
done
SH;
    }

    /**
     * Parse the delimited gate-log output produced by buildGateLogReadScript().
     *
     * @return array<string, string>
     */
    private function parseGateLogOutput(string $output): array
    {
        $logs = [];
        $pattern = '/###GATE-LOG-START###([^#]+)###\d+###\r?\n(.*?)\r?\n###GATE-LOG-END###/s';
        if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) === false) {
            return $logs;
        }
        foreach ($matches as $match) {
            $key = trim($match[1]);
            $body = $this->toValidUtf8($match[2]);
            if ($key !== '') {
                // Normalize internal key: worker emits e.g. "artisan-smoke",
                // but the UI/quality_gates output uses "artisan".
                $key = $this->normalizeGateLogKey($key);
                $logs[$key] = $body;
            }
        }

        return $logs;
    }

    /**
     * Strip malformed UTF-8 from a gate-log body. The shell reader truncates
     * oversized logs byte-wise (head -c / tail -c in buildGateLogReadScript),
     * which can slice a multi-byte UTF-8 character at the cut boundary. The
     * resulting invalid sequence makes json_encode() throw a
     * JsonEncodingException when the value is cast into the json
     * `quality_gate_logs` column (PhaseRun) — which previously flipped an
     * otherwise-successful implement run to "failed". Substituting the invalid
     * bytes keeps the diagnostic log readable and the JSON cast safe.
     */
    private function toValidUtf8(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Map the on-disk filename base to the gate slug used in
     * PhaseRun.result_json.quality_gates and the UI.
     */
    private function normalizeGateLogKey(string $key): string
    {
        $base = $key;
        $suffix = '';
        if (str_contains($key, '.')) {
            [$base, $suffix] = explode('.', $key, 2);
        }
        $flipped = array_flip(self::QUALITY_GATE_LOG_BASES);
        $normalizedBase = $flipped[$base] ?? $base;

        return $suffix === '' ? $normalizedBase : "{$normalizedBase}.{$suffix}";
    }
}
