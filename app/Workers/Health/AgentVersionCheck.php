<?php

declare(strict_types=1);

namespace App\Workers\Health;

use App\Enums\AgentName;
use App\Models\AgentVersion;
use App\Workers\Agents\AgentRegistry;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Polls the npm registry for each registered agent's package and
 * compares the latest tag with the version pinned in the AgentSpec.
 * Sets has_update = true on agent_versions when they drift.
 *
 * Runs daily (or on demand). Wrapped in a service for testability —
 * the Artisan command and scheduler both call ::run().
 */
class AgentVersionCheck
{
    public int $timeoutSeconds = 30;

    public function __construct(private readonly AgentRegistry $registry) {}

    /**
     * @return array<string, array{installed: string, upstream: ?string, has_update: bool}>
     */
    public function run(): array
    {
        $report = [];

        foreach ($this->registry->specs() as $spec) {
            if ($spec->npmPackage === '') {
                continue;
            }

            $upstream = $this->latestNpmVersion($spec->npmPackage);
            $installed = $spec->pinnedVersion;
            $hasUpdate = $this->isUpdateAvailable($installed, $upstream);

            AgentVersion::query()->updateOrCreate(
                ['agent_name' => $spec->name->value],
                [
                    'installed_version' => $installed,
                    'upstream_version' => $upstream,
                    'has_update' => $hasUpdate,
                    'last_checked_at' => now(),
                ],
            );

            $report[$spec->name->value] = [
                'installed' => $installed,
                'upstream' => $upstream,
                'has_update' => $hasUpdate,
            ];
        }

        return $report;
    }

    public function checkOne(AgentName $name): ?string
    {
        if (! $this->registry->has($name)) {
            return null;
        }

        $spec = $this->registry->get($name)::spec();

        return $this->latestNpmVersion($spec->npmPackage);
    }

    private function latestNpmVersion(string $package): ?string
    {
        try {
            $process = $this->newProcess(['npm', 'view', $package, 'version']);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            $version = trim($process->getOutput());

            return $version === '' ? null : $version;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "latest" pin always reports an update available when there is an
     * upstream tag — the user told us to track latest, so any movement
     * deserves a rebuild signal.
     */
    private function isUpdateAvailable(string $installed, ?string $upstream): bool
    {
        if ($upstream === null) {
            return false;
        }
        if ($installed === 'latest') {
            return true;
        }

        return $installed !== $upstream;
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
