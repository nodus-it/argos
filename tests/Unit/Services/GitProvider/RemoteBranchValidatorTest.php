<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Services\GitProvider\RemoteBranchValidator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemoteBranchValidatorTest extends TestCase
{
    public function test_returns_ok_when_git_ls_remote_exits_zero(): void
    {
        $validator = $this->validatorWithProcess(exitCode: 0);

        $result = $validator->validate('https://github.com/foo/bar', 'main');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function test_returns_branch_not_found_on_exit_code_two(): void
    {
        $validator = $this->validatorWithProcess(exitCode: 2);

        $result = $validator->validate('https://github.com/foo/bar', 'main');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('main', (string) $result['error']);
        $this->assertStringContainsString('nicht', (string) $result['error']);
    }

    public function test_returns_generic_error_on_other_exit_codes(): void
    {
        $validator = $this->validatorWithProcess(exitCode: 128, stderr: "fatal: repository not found\n");

        $result = $validator->validate('https://github.com/foo/missing', 'main');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('repository not found', (string) $result['error']);
    }

    public function test_scrubs_oauth_token_from_error_message(): void
    {
        $stderr = "fatal: unable to access 'https://oauth2:s3cret@github.com/foo/bar.git/'\n";
        $validator = $this->validatorWithProcess(exitCode: 128, stderr: $stderr);

        $result = $validator->validate('https://github.com/foo/bar', 'main', token: 's3cret');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('s3cret', (string) $result['error']);
        $this->assertStringContainsString('://***@', (string) $result['error']);
    }

    public function test_scrubs_bitbucket_x_token_auth_from_error_message(): void
    {
        $stderr = "fatal: unable to access 'https://x-token-auth:s3cret@bitbucket.org/ws/repo/'\n";
        $validator = $this->validatorWithProcess(exitCode: 128, stderr: $stderr);

        $result = $validator->validate('https://bitbucket.org/ws/repo', 'main', token: 's3cret');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('s3cret', (string) $result['error']);
        $this->assertStringContainsString('://***@', (string) $result['error']);
    }

    public function test_passes_token_via_url_when_https(): void
    {
        $captured = null;
        $validator = $this->validatorCapturingCommand(0, $captured);

        $validator->validate('https://github.com/foo/bar', 'main', token: 'tok-123');

        $this->assertNotNull($captured);
        $this->assertContains('https://oauth2:tok-123@github.com/foo/bar', $captured);
    }

    public function test_uses_x_token_auth_for_bitbucket_bearer_token(): void
    {
        $captured = null;
        $validator = $this->validatorCapturingCommand(0, $captured);

        $validator->validate('https://bitbucket.org/ws/repo', 'main', token: 'no-colon-token');

        $this->assertNotNull($captured);
        $this->assertContains('https://x-token-auth:no-colon-token@bitbucket.org/ws/repo', $captured);
    }

    public function test_uses_basic_user_info_for_bitbucket_token_with_colon(): void
    {
        $captured = null;
        $validator = $this->validatorCapturingCommand(0, $captured);

        $validator->validate('https://bitbucket.org/ws/repo', 'main', token: 'user:secret');

        $this->assertNotNull($captured);
        $this->assertContains('https://user:secret@bitbucket.org/ws/repo', $captured);
    }

    public function test_does_not_inject_token_for_non_https_urls(): void
    {
        $captured = null;
        $validator = $this->validatorCapturingCommand(0, $captured);

        $validator->validate('git@github.com:foo/bar.git', 'main', token: 'tok-123');

        $this->assertNotNull($captured);
        $this->assertContains('git@github.com:foo/bar.git', $captured);
    }

    public function test_returns_error_for_empty_url_or_branch(): void
    {
        $validator = new RemoteBranchValidator;

        $this->assertFalse($validator->validate('', 'main')['ok']);
        $this->assertFalse($validator->validate('https://github.com/foo/bar', '')['ok']);
    }

    private function validatorWithProcess(int $exitCode, string $stderr = ''): RemoteBranchValidator
    {
        return new class($exitCode, $stderr) extends RemoteBranchValidator
        {
            public function __construct(private readonly int $exitCode, private readonly string $stderr) {}

            protected function newProcess(array $cmd): Process
            {
                $mock = \Mockery::mock(Process::class);
                $mock->shouldReceive('setTimeout')->andReturnSelf();
                $mock->shouldReceive('run')->andReturn($this->exitCode);
                $mock->shouldReceive('getExitCode')->andReturn($this->exitCode);
                $mock->shouldReceive('getErrorOutput')->andReturn($this->stderr);

                return $mock;
            }
        };
    }

    private function validatorCapturingCommand(int $exitCode, ?array &$captured): RemoteBranchValidator
    {
        return new class($exitCode, $captured) extends RemoteBranchValidator
        {
            /**
             * @param  array<int, string>|null  $captured
             */
            public function __construct(private readonly int $exitCode, private ?array &$captured) {}

            protected function newProcess(array $cmd): Process
            {
                $this->captured = $cmd;
                $mock = \Mockery::mock(Process::class);
                $mock->shouldReceive('setTimeout')->andReturnSelf();
                $mock->shouldReceive('run')->andReturn($this->exitCode);
                $mock->shouldReceive('getExitCode')->andReturn($this->exitCode);
                $mock->shouldReceive('getErrorOutput')->andReturn('');

                return $mock;
            }
        };
    }
}
