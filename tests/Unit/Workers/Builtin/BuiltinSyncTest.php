<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Builtin;

use App\Enums\WorkerImageEntityStatus;
use App\Models\WorkerStack;
use App\Workers\Builtin\BuiltinManifest;
use App\Workers\Builtin\BuiltinSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuiltinSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestDir = sys_get_temp_dir().'/argos-builtin-sync-'.uniqid();
        mkdir($this->manifestDir.'/stacks', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->manifestDir);
        parent::tearDown();
    }

    public function test_creates_built_ins_on_empty_database(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);

        $summary = $this->buildSync()->sync();

        $this->assertSame(1, $summary['created']);

        $stack = WorkerStack::query()->where('name', 'php-8.4')->first();
        $this->assertNotNull($stack);
        $this->assertTrue($stack->is_builtin);
        $this->assertSame(WorkerImageEntityStatus::Active, $stack->status);
        $this->assertNotNull($stack->last_builtin_hash);
        $this->assertStringContainsString('FROM php', $stack->dockerfile_body);
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);

        $this->buildSync()->sync();
        $summary = $this->buildSync()->sync();

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, $summary['unchanged']);
    }

    public function test_dockerfile_change_triggers_update(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);
        $this->buildSync()->sync();

        $beforeHash = WorkerStack::query()->where('name', 'php-8.4')->value('last_builtin_hash');

        $this->writeStackDockerfile('php-8.4', "FROM php:8.4-cli-bookworm\nRUN apt-get install -y new-tool\n");

        $summary = $this->buildSync()->sync();

        $this->assertSame(1, $summary['updated']);

        $afterHash = WorkerStack::query()->where('name', 'php-8.4')->value('last_builtin_hash');
        $this->assertNotSame($beforeHash, $afterHash);
        $this->assertStringContainsString('new-tool', WorkerStack::query()->where('name', 'php-8.4')->value('dockerfile_body'));
    }

    public function test_manifest_label_change_triggers_update(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);
        $this->buildSync()->sync();

        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4 (LTS)')],
        ]);
        $summary = $this->buildSync()->sync();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('PHP 8.4 (LTS)', WorkerStack::query()->where('name', 'php-8.4')->value('label'));
    }

    public function test_built_in_removed_from_manifest_is_deprecated_not_deleted(): void
    {
        $this->writeManifest([
            'stacks' => [
                $this->stackEntry('php-8.3', 'PHP 8.3'),
                $this->stackEntry('php-8.4', 'PHP 8.4'),
            ],
        ]);
        $this->buildSync()->sync();

        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);
        $summary = $this->buildSync()->sync();

        $this->assertSame(1, $summary['deprecated']);

        $orphan = WorkerStack::query()->where('name', 'php-8.3')->first();
        $this->assertNotNull($orphan, 'deprecated row must remain in DB');
        $this->assertSame(WorkerImageEntityStatus::Deprecated, $orphan->status);
    }

    public function test_user_created_rows_are_never_touched(): void
    {
        $this->writeManifest(['stacks' => []]);

        $userStack = WorkerStack::factory()->create([
            'name' => 'my-custom',
            'is_builtin' => false,
            'status' => WorkerImageEntityStatus::Active,
        ]);

        $this->buildSync()->sync();

        $reloaded = $userStack->fresh();
        $this->assertFalse($reloaded->is_builtin);
        $this->assertSame(WorkerImageEntityStatus::Active, $reloaded->status);
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);

        $summary = $this->buildSync()->sync(dryRun: true);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, WorkerStack::query()->count());
    }

    public function test_deprecated_built_in_is_reactivated_when_returned_to_manifest(): void
    {
        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);
        $this->buildSync()->sync();

        $this->writeManifest(['stacks' => []]);
        $this->buildSync()->sync();
        $this->assertSame(WorkerImageEntityStatus::Deprecated, WorkerStack::query()->where('name', 'php-8.4')->value('status'));

        $this->writeManifest([
            'stacks' => [$this->stackEntry('php-8.4', 'PHP 8.4')],
        ]);
        $summary = $this->buildSync()->sync();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(WorkerImageEntityStatus::Active, WorkerStack::query()->where('name', 'php-8.4')->value('status'));
    }

    /**
     * @param  array{stacks: list<array<string, mixed>>}  $manifest
     */
    private function writeManifest(array $manifest): void
    {
        foreach ($manifest['stacks'] as $stack) {
            $this->writeStackDockerfile(
                $stack['name'],
                "FROM {$stack['base_image']}\nRUN apt-get install -y curl\n",
            );
        }

        file_put_contents(
            $this->manifestDir.'/built-ins.php',
            '<?php return '.var_export($manifest, true).';',
        );
    }

    private function writeStackDockerfile(string $name, string $body): void
    {
        file_put_contents($this->manifestDir."/stacks/Dockerfile.{$name}", $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function stackEntry(string $name, string $label): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'base_image' => "php:{$name}-cli-bookworm",
            'dockerfile' => "stacks/Dockerfile.{$name}",
            'capabilities' => ['php', 'composer'],
            'common_tools' => ['git', 'jq'],
        ];
    }

    private function buildSync(): BuiltinSync
    {
        return new BuiltinSync(new BuiltinManifest($this->manifestDir.'/built-ins.php'));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
