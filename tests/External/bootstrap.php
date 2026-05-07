<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$envFile = __DIR__.'/../../.env.testing.external';

if (is_file($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile), basename($envFile));
    $dotenv->load();

    // Mirror the values into getenv() — ProviderTestConfig reads them via getenv().
    foreach ($_ENV as $key => $value) {
        if (! is_string($value)) {
            continue;
        }
        if (getenv($key) === false || getenv($key) === '') {
            putenv("{$key}={$value}");
        }
    }
}
