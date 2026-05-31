<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentCredential;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Workflow\TaskLogBundleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;
use ZipArchive;

class TaskLogBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_bundle_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config(['argos.config_dir' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->rmdirRecursive($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_bundle_contains_task_json_phase_runs_and_manifest(): void
    {
        $task = $this->taskWithRuns();

        $builder = $this->builderWithVolumeOutput('');
        $zipPath = $builder->build($task);

        $entries = $this->listEntries($zipPath);
        $this->assertContains('task.json', $entries);
        $this->assertContains('phase_runs.json', $entries);
        $this->assertContains('MANIFEST.txt', $entries);

        $phaseRuns = json_decode($this->readEntry($zipPath, 'phase_runs.json'), true);
        $this->assertCount(1, $phaseRuns);
        $this->assertSame('implement', $phaseRuns[0]['phase']);

        @unlink($zipPath);
    }

    public function test_bundle_redacts_sensitive_fields_from_task_json(): void
    {
        $task = $this->taskWithRuns();
        $cred = AgentCredential::factory()->create([
            'credentials' => ['token' => 'sk-ant-real-secret'],
        ]);
        $task->update(['agent_credential_id' => $cred->id]);

        $builder = $this->builderWithVolumeOutput('');
        $zipPath = $builder->build($task);

        $taskJson = $this->readEntry($zipPath, 'task.json');
        $this->assertStringNotContainsString('sk-ant-real-secret', $taskJson);
        $this->assertStringContainsString('***REDACTED***', $taskJson);

        @unlink($zipPath);
    }

    public function test_bundle_includes_workspace_files_streamed_from_docker(): void
    {
        $task = $this->taskWithRuns();

        $dockerOutput = "###BUNDLE-FILE-START###state.json###2###\n"
            ."{}\n###BUNDLE-FILE-END###\n"
            ."###BUNDLE-FILE-START###logs/implement.1.stream.log###13###\n"
            ."hello world!!\n###BUNDLE-FILE-END###\n";

        $builder = $this->builderWithVolumeOutput($dockerOutput);
        $zipPath = $builder->build($task);

        $entries = $this->listEntries($zipPath);
        $this->assertContains('workspace/state.json', $entries);
        $this->assertContains('workspace/logs/implement.1.stream.log', $entries);
        $this->assertSame('hello world!!', $this->readEntry($zipPath, 'workspace/logs/implement.1.stream.log'));

        @unlink($zipPath);
    }

    public function test_bundle_includes_host_bg_log_files(): void
    {
        $task = $this->taskWithRuns();
        $taskLogDir = "{$this->tmpDir}/tasks/{$task->name}";
        mkdir($taskLogDir, 0755, true);
        file_put_contents("{$taskLogDir}/implement.bg.log", "host log content\n");

        $builder = $this->builderWithVolumeOutput('');
        $zipPath = $builder->build($task);

        $entries = $this->listEntries($zipPath);
        $this->assertContains('host-logs/implement.bg.log', $entries);
        $this->assertSame("host log content\n", $this->readEntry($zipPath, 'host-logs/implement.bg.log'));

        @unlink($zipPath);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function taskWithRuns(): Task
    {
        $profile = RepoProfile::factory()->create();
        $task = Task::factory()->create([
            'name' => 'bundle-test-task',
            'repo_profile_id' => $profile->id,
        ]);
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'failed',
            'exit_code' => 3,
            'cost_usd' => 1.23,
        ]);

        return $task;
    }

    private function builderWithVolumeOutput(string $dockerOutput): TaskLogBundleBuilder
    {
        return $this->partialMock(TaskLogBundleBuilder::class, function (MockInterface $mock) use ($dockerOutput): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('readVolumeStream')->andReturn($dockerOutput);
        });
    }

    /** @return list<string> */
    private function listEntries(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    private function readEntry(string $zipPath, string $entry): string
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $content = (string) $zip->getFromName($entry);
        $zip->close();

        return $content;
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            if (is_dir($item)) {
                $this->rmdirRecursive($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
