<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use Symfony\Component\Process\Process;

/**
 * Probes a remote git repository with `git ls-remote` to verify that a given
 * branch exists, before the worker tries to clone it. Used as a guard in the
 * RepoProfile form so users can't save a default_branch that the worker would
 * later fail to fetch.
 */
class RemoteBranchValidator
{
    /**
     * @return array{ok: bool, error: string|null}
     */
    public function validate(string $url, string $branch, ?string $token = null): array
    {
        if ($url === '' || $branch === '') {
            return ['ok' => false, 'error' => 'URL oder Branch leer.'];
        }

        $authUrl = $this->injectToken($url, $token);

        $process = $this->newProcess([
            'git', 'ls-remote', '--exit-code', '--heads', $authUrl, $branch,
        ]);
        $process->setTimeout(15);
        $process->run();

        $exitCode = $process->getExitCode();

        return match ($exitCode) {
            0 => ['ok' => true, 'error' => null],
            2 => ['ok' => false, 'error' => "Branch '{$branch}' nicht im Repository gefunden."],
            default => ['ok' => false, 'error' => $this->extractError($process->getErrorOutput()) ?: 'git ls-remote fehlgeschlagen.'],
        };
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }

    private function injectToken(string $url, ?string $token): string
    {
        if ($token === null || $token === '' || ! preg_match('#^(https?://)(.*)$#', $url, $m)) {
            return $url;
        }

        // Provider-specific user-info shape, mirroring BitbucketGitService's
        // auto-detection: a Bitbucket token containing a colon is "user:secret"
        // (Basic), one without is Bearer-style and needs the magic
        // x-token-auth user. GitHub and GitLab take any token via oauth2:<token>.
        $userInfo = match (true) {
            str_contains($m[2], 'bitbucket.org') && str_contains($token, ':') => $token,
            str_contains($m[2], 'bitbucket.org') => "x-token-auth:{$token}",
            default => "oauth2:{$token}",
        };

        return $m[1].$userInfo.'@'.$m[2];
    }

    private function extractError(string $stderr): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stderr)), fn (string $l) => $l !== '');
        if ($lines === []) {
            return null;
        }

        return $this->scrubToken(end($lines));
    }

    private function scrubToken(string $line): string
    {
        // Match any embedded user-info segment ("user:secret" or "x-token-auth:token"
        // or "oauth2:token") between scheme:// and @, replace with ***.
        return preg_replace('#(://)[^/@\s]+@#', '$1***@', $line) ?? $line;
    }
}
