<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Services\Workflow\RunResourceReaper;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Records the docker commands the reaper issues and answers query commands with
 * canned output (via printf) — no real Docker needed.
 */
class RecordingReaper extends RunResourceReaper
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var array<string, string> substring of joined command => stdout to return */
    public array $outputs = [];

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        $this->commands[] = $cmd;
        $joined = implode(' ', $cmd);

        foreach ($this->outputs as $needle => $out) {
            if (str_contains($joined, $needle)) {
                return new Process(['printf', '%s', $out]);
            }
        }

        return new Process(['true']);
    }

    /** @return list<string> */
    public function joined(): array
    {
        return array_map(static fn (array $c): string => implode(' ', $c), $this->commands);
    }
}

class RunResourceReaperTest extends TestCase
{
    public function test_reap_task_force_removes_its_containers_and_network(): void
    {
        $reaper = new RecordingReaper;
        $reaper->outputs = [
            'ps -aq --filter label=argos.task=T1' => "c1\nc2\n",
            'network ls -q --filter label=argos.task=T1' => "n1\n",
        ];

        $reaper->reapTask('T1');

        $joined = $reaper->joined();
        $this->assertContains('docker rm -f c1', $joined);
        $this->assertContains('docker rm -f c2', $joined);
        $this->assertContains('docker network rm n1', $joined);
    }

    public function test_reap_task_is_a_noop_when_nothing_is_labelled(): void
    {
        $reaper = new RecordingReaper;
        $reaper->reapTask('T1');

        // Only the two list queries ran; no removal commands.
        $this->assertFalse($this->anyRemoval($reaper->joined()));
    }

    public function test_reap_except_reaps_only_tasks_not_in_keep_set(): void
    {
        $reaper = new RecordingReaper;
        $reaper->outputs = [
            // The label universe: containers of T1 + T2, a network of T2.
            'ps -a --filter label=argos.role' => "T1\nT2\n",
            'network ls --filter label=argos.role' => 'argos.role=network,argos.task=T2',
            // Per-task reap queries for the orphan T2.
            'ps -aq --filter label=argos.task=T2' => "c9\n",
            'network ls -q --filter label=argos.task=T2' => "n9\n",
        ];

        $reaped = $reaper->reapExcept(['T1']);

        $this->assertSame(1, $reaped);
        $joined = $reaper->joined();
        $this->assertContains('docker rm -f c9', $joined);
        $this->assertContains('docker network rm n9', $joined);
        // T1 is kept — it is never reaped.
        $this->assertFalse(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'argos.task=T1')),
        );
    }

    public function test_reap_except_keeps_everything_when_all_running(): void
    {
        $reaper = new RecordingReaper;
        $reaper->outputs = [
            'ps -a --filter label=argos.role' => "T1\nT2\n",
            'network ls --filter label=argos.role' => '',
        ];

        $reaped = $reaper->reapExcept(['T1', 'T2']);

        $this->assertSame(0, $reaped);
        $this->assertFalse($this->anyRemoval($reaper->joined()));
    }

    /**
     * True when any issued command is a container/network removal (and not just
     * a query — `--format` contains the substring "rm", so match precisely).
     *
     * @param  list<string>  $joined
     */
    private function anyRemoval(array $joined): bool
    {
        return (bool) array_filter(
            $joined,
            static fn (string $c): bool => str_contains($c, 'docker rm -f')
                || str_contains($c, 'docker network rm'),
        );
    }
}
