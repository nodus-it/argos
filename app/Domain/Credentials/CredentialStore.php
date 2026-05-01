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

    public function getClaudeToken(): ?string
    {
        $path = $this->configDir . '/claude_token';

        if (!is_file($path)) {
            return null;
        }

        $token = trim((string) file_get_contents($path));

        return $token !== '' ? $token : null;
    }

    public function saveClaudeToken(string $token): void
    {
        $this->ensureConfigDir();

        $path = $this->configDir . '/claude_token';
        file_put_contents($path, $token);
        chmod($path, 0600);
    }

    public function getDbConfig(): ?array
    {
        $path = $this->configDir . '/db.env';

        if (!is_file($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $config[trim($key)] = trim($value);
        }

        return $config !== [] ? $config : null;
    }

    public function saveDbConfig(array $config): void
    {
        $this->ensureConfigDir();

        $path = $this->configDir . '/db.env';
        $lines = array_map(
            fn(string $key, string $value) => "{$key}={$value}",
            array_keys($config),
            array_values($config),
        );
        file_put_contents($path, implode("\n", $lines) . "\n");
        chmod($path, 0600);
    }

    private function ensureConfigDir(): void
    {
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, true);
        }
    }
}
