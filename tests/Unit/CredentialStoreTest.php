<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Credentials\CredentialStore;
use Tests\TestCase;

class CredentialStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_cred_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config(['argos.config_dir' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_returns_null_when_file_does_not_exist(): void
    {
        $store = new CredentialStore;
        $this->assertNull($store->getClaudeToken());
    }

    public function test_returns_null_when_file_is_empty(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', '');
        $store = new CredentialStore;
        $this->assertNull($store->getClaudeToken());
    }

    public function test_returns_null_when_file_contains_only_whitespace(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', "   \n\t  ");
        $store = new CredentialStore;
        $this->assertNull($store->getClaudeToken());
    }

    public function test_returns_token_from_file(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', 'my-oauth-token');
        $store = new CredentialStore;
        $this->assertSame('my-oauth-token', $store->getClaudeToken());
    }

    public function test_trims_leading_and_trailing_whitespace(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', "  tok123  \n");
        $store = new CredentialStore;
        $this->assertSame('tok123', $store->getClaudeToken());
    }
}
