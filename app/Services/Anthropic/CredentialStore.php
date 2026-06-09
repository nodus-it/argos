<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

class CredentialStore
{
    /**
     * Returns the Claude OAuth token from the config-dir file, or null if not set.
     * Legacy local-dev fallback only; the authoritative path is a per-agent
     * AgentCredential row created via the onboarding/credentials UI.
     */
    public function getClaudeToken(): ?string
    {
        $path = $this->tokenPath();

        if (! is_file($path)) {
            return null;
        }

        $token = trim((string) file_get_contents($path));

        return $token !== '' ? $token : null;
    }

    /**
     * Persist the Claude OAuth token to the config-dir file with restrictive permissions.
     */
    public function setClaudeToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            $this->deleteClaudeToken();

            return;
        }

        $configDir = $this->configDir();
        if (! is_dir($configDir)) {
            mkdir($configDir, 0700, true);
        }

        $path = $this->tokenPath();
        file_put_contents($path, $token);
        chmod($path, 0600);
    }

    public function deleteClaudeToken(): void
    {
        $path = $this->tokenPath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * True if a token is available via the on-disk file.
     */
    public function hasClaudeToken(): bool
    {
        return $this->getClaudeToken() !== null;
    }

    /**
     * Where the token comes from: 'file' or 'none'.
     */
    public function claudeTokenSource(): string
    {
        return $this->getClaudeToken() !== null ? 'file' : 'none';
    }

    private function configDir(): string
    {
        return (string) config('argos.config_dir');
    }

    private function tokenPath(): string
    {
        return $this->configDir().'/claude_token';
    }
}
