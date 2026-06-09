<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\DemoAccessMode;
use App\Models\Task;
use Illuminate\Support\Str;

class DemoAccessConfigurator
{
    public function __construct(private readonly DemoDeployer $deployer) {}

    /**
     * Persist the demo access mode and re-apply it to the live deployment.
     *
     * For basic auth the password is the entered one, the existing one, or a
     * freshly generated 16-char string — so a basic demo is never passwordless.
     * Returns the effective password so the caller can surface the credentials.
     *
     * @throws \RuntimeException when re-applying to the running demo fails
     */
    public function apply(Task $task, DemoAccessMode $mode, ?string $enteredPassword): ?string
    {
        $password = $task->demo_basic_password;
        if ($mode->resolve() === DemoAccessMode::Basic) {
            $password = ($enteredPassword ?: null) ?? ($password ?: Str::random(16));
        }

        $task->update([
            'demo_access_mode' => $mode,
            'demo_basic_password' => $password,
        ]);

        $this->deployer->applyAccessMode($task);

        return $password;
    }
}
