<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Pulls external commits a user pushed to the feature branch into the task
 * volume before a demo (re)build, so the live demo reflects the current remote
 * state — not the stale local checkout (I2 — external branch collaboration).
 *
 * Safety: this resets the *shared* task volume to the remote tip, which would
 * discard uncommitted work. It therefore only runs when the working tree is
 * **clean** — i.e. after a push phase committed everything (the real external-
 * collaboration window). A dirty tree (e.g. the auto-deploy right after
 * implement, before push) is left untouched so in-progress work is never lost.
 * Best-effort: any failure is logged and the deploy proceeds on whatever the
 * volume already holds.
 */
class DemoWorkspaceSync
{
    public function syncToRemote(Task $task): void
    {
        $branch = (string) $task->feature_branch;
        $profile = $task->repoProfile;
        if ($branch === '' || $profile === null) {
            return;
        }

        try {
            $token = $profile->resolveToken();
        } catch (\Throwable $e) {
            Log::channel('argos')->info('Demo workspace sync skipped: no usable token', [
                'task' => $task->id,
            ]);

            return;
        }

        $authHeader = $this->authHeader($token, $profile->platform->value);
        $vol = $task->volumeName();
        $g = "git -c safe.directory='*' -C /workspace";
        $b = escapeshellarg($branch);

        // Skip on a dirty tree (uncommitted/untracked work) — see class docblock.
        // Otherwise fetch the branch and fast-forward the volume to the remote
        // tip; -fd without -x keeps gitignored vendor/ and node_modules/.
        $script = "[ -n \"\$({$g} status --porcelain)\" ] && exit 0; "
            ."{$g} -c http.extraheader=\"\$GIT_AUTH_HEADER\" fetch --quiet origin {$b} || exit 0; "
            ."{$g} checkout -B {$b} FETCH_HEAD && {$g} clean -fd";

        $result = Process::timeout(60)
            ->env(['GIT_AUTH_HEADER' => $authHeader])
            ->run([
                'docker', 'run', '--rm',
                '-e', 'GIT_AUTH_HEADER',
                '-v', "{$vol}:/workspace",
                '--entrypoint', 'sh', 'alpine/git',
                '-c', $script,
            ]);

        if (! $result->successful()) {
            Log::channel('argos')->warning('Demo workspace sync failed (deploying current state)', [
                'task' => $task->id,
                'branch' => $branch,
            ]);
        }
    }

    /**
     * Build the `Authorization: Basic …` header value the volume's token-less
     * origin needs. Mirrors the worker's git_auth_header (credentials.sh).
     */
    private function authHeader(string $token, string $platform): string
    {
        if (str_contains($token, ':')) {
            $creds = $token;
        } elseif ($platform === 'bitbucket') {
            $creds = 'x-token-auth:'.$token;
        } else {
            $creds = 'oauth2:'.$token;
        }

        return 'Authorization: Basic '.base64_encode($creds);
    }
}
