<?php

declare(strict_types=1);

namespace Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $passportKeysEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeys();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Passport's OAuth signing keys are provisioned at deploy time
     * (`passport:install` locally, `passport:keys` in the Docker entrypoint)
     * and are gitignored. A clean checkout — CI in particular — has none, so
     * any test that exercises the Passport `api` guard (the MCP feature tests)
     * throws "Invalid key supplied". Generate them once per test run, mirroring
     * the entrypoint. Tests run sequentially, so the file check is race-free.
     */
    private function ensurePassportKeys(): void
    {
        if (self::$passportKeysEnsured) {
            return;
        }

        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }

        self::$passportKeysEnsured = true;
    }
}
