<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

class CredentialStore
{
    private string $configDir;

    public function __construct()
    {
        $this->configDir = config('argos.config_dir');
    }

    /**
     * Returns the Claude OAuth token from the config-dir file, or null if not set.
     * Falls back for local dev — in production, use CLAUDE_CODE_OAUTH_TOKEN env var.
     */
    public function getClaudeToken(): ?string
    {
        $path = $this->configDir . '/claude_token';

        if (! is_file($path)) {
            return null;
        }

        $token = trim((string) file_get_contents($path));

        return $token !== '' ? $token : null;
    }
}
