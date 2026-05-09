<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workers\Health\AgentVersionCheck;
use Illuminate\Console\Command;

class CheckAgentVersions extends Command
{
    protected $signature = 'argos:check-agent-versions';

    protected $description = 'Poll the npm registry for upstream versions of every registered agent.';

    public function handle(AgentVersionCheck $check): int
    {
        $report = $check->run();

        if ($report === []) {
            $this->info('No agents registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Agent', 'Pinned', 'Upstream', 'Update?'],
            array_map(
                fn (string $name, array $row) => [
                    $name,
                    $row['installed'],
                    $row['upstream'] ?? '(unavailable)',
                    $row['has_update'] ? 'yes' : 'no',
                ],
                array_keys($report),
                array_values($report),
            ),
        );

        return self::SUCCESS;
    }
}
