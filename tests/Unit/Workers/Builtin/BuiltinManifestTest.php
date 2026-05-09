<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Builtin;

use App\Workers\Builtin\BuiltinManifest;
use RuntimeException;
use Tests\TestCase;

class BuiltinManifestTest extends TestCase
{
    public function test_default_manifest_loads_from_repo(): void
    {
        $manifest = BuiltinManifest::default();

        $names = array_column($manifest->stacks(), 'name');
        $this->assertContains('php-8.3', $names);
        $this->assertContains('php-8.4', $names);
    }

    public function test_default_manifest_referenced_files_exist(): void
    {
        $manifest = BuiltinManifest::default();

        foreach ($manifest->stacks() as $stack) {
            $body = $manifest->readFile($stack['dockerfile']);
            $this->assertStringContainsString('FROM', $body);
        }
    }

    public function test_missing_manifest_file_throws(): void
    {
        $this->expectException(RuntimeException::class);

        new BuiltinManifest('/nonexistent/built-ins.php');
    }

    public function test_malformed_manifest_throws(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'argos-manifest-');
        file_put_contents($tmp, "<?php return 'not-an-array';");

        try {
            $this->expectException(RuntimeException::class);
            new BuiltinManifest($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function test_read_file_throws_for_missing_referenced_file(): void
    {
        $tmpDir = sys_get_temp_dir().'/argos-manifest-'.uniqid();
        mkdir($tmpDir);
        file_put_contents(
            $tmpDir.'/built-ins.php',
            '<?php return ["stacks" => []];',
        );

        $manifest = new BuiltinManifest($tmpDir.'/built-ins.php');

        try {
            $this->expectException(RuntimeException::class);
            $manifest->readFile('does-not-exist.txt');
        } finally {
            unlink($tmpDir.'/built-ins.php');
            rmdir($tmpDir);
        }
    }
}
