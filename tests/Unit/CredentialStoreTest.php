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

    public function test_set_claude_token_writes_file_with_restrictive_mode(): void
    {
        $store = new CredentialStore;
        $store->setClaudeToken('sk-ant-oat01-foo');

        $path = $this->tmpDir.'/claude_token';
        $this->assertFileExists($path);
        $this->assertSame('sk-ant-oat01-foo', $store->getClaudeToken());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function test_set_claude_token_trims_whitespace(): void
    {
        $store = new CredentialStore;
        $store->setClaudeToken("  spaced  \n");

        $this->assertSame('spaced', $store->getClaudeToken());
    }

    public function test_set_claude_token_with_empty_string_deletes_file(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', 'old-token');
        $store = new CredentialStore;
        $store->setClaudeToken('   ');

        $this->assertFileDoesNotExist($this->tmpDir.'/claude_token');
        $this->assertNull($store->getClaudeToken());
    }

    public function test_delete_claude_token_removes_file(): void
    {
        file_put_contents($this->tmpDir.'/claude_token', 'tok');
        $store = new CredentialStore;
        $store->deleteClaudeToken();

        $this->assertFileDoesNotExist($this->tmpDir.'/claude_token');
    }

    public function test_delete_claude_token_when_missing_is_a_noop(): void
    {
        $store = new CredentialStore;
        $store->deleteClaudeToken();

        $this->assertFileDoesNotExist($this->tmpDir.'/claude_token');
    }

    public function test_has_claude_token_uses_env_when_set(): void
    {
        config(['argos.claude_token' => 'env-tok']);
        $store = new CredentialStore;

        $this->assertTrue($store->hasClaudeToken());
        $this->assertSame('env', $store->claudeTokenSource());
    }

    public function test_has_claude_token_falls_back_to_file(): void
    {
        config(['argos.claude_token' => null]);
        file_put_contents($this->tmpDir.'/claude_token', 'file-tok');
        $store = new CredentialStore;

        $this->assertTrue($store->hasClaudeToken());
        $this->assertSame('file', $store->claudeTokenSource());
    }

    public function test_has_claude_token_returns_false_when_neither_set(): void
    {
        config(['argos.claude_token' => null]);
        $store = new CredentialStore;

        $this->assertFalse($store->hasClaudeToken());
        $this->assertSame('none', $store->claudeTokenSource());
    }

    public function test_set_claude_token_creates_config_dir_if_missing(): void
    {
        $missingDir = $this->tmpDir.'/nested/path';
        config(['argos.config_dir' => $missingDir]);

        $store = new CredentialStore;
        $store->setClaudeToken('new-tok');

        $this->assertDirectoryExists($missingDir);
        $this->assertSame('new-tok', $store->getClaudeToken());

        unlink($missingDir.'/claude_token');
        rmdir($missingDir);
        rmdir($this->tmpDir.'/nested');
    }
}
