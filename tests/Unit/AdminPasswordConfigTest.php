<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * Sichert ab, dass eine leere ADMIN_PASSWORD-Env (z.B. wenn ein Wrapper-Skript
 * einen optionalen Wert als `-e ADMIN_PASSWORD=""` an docker run durchreicht)
 * den Default aus config/argos.php greifen lässt — Laravels env() liefert für
 * gesetzte, leere Variablen "" statt des Default-Arguments zurück.
 */
final class AdminPasswordConfigTest extends TestCase
{
    private mixed $originalAdminPassword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAdminPassword = Env::getRepository()->get('ADMIN_PASSWORD');
    }

    protected function tearDown(): void
    {
        if ($this->originalAdminPassword === null) {
            Env::getRepository()->clear('ADMIN_PASSWORD');
        } else {
            Env::getRepository()->set('ADMIN_PASSWORD', (string) $this->originalAdminPassword);
        }
        parent::tearDown();
    }

    public function test_admin_password_falls_back_to_default_when_env_is_empty_string(): void
    {
        Env::getRepository()->set('ADMIN_PASSWORD', '');

        $config = require base_path('config/argos.php');

        $this->assertSame('12345', $config['admin_password']);
    }

    public function test_admin_password_falls_back_to_default_when_env_is_unset(): void
    {
        Env::getRepository()->clear('ADMIN_PASSWORD');

        $config = require base_path('config/argos.php');

        $this->assertSame('12345', $config['admin_password']);
    }

    public function test_admin_password_uses_env_value_when_set(): void
    {
        Env::getRepository()->set('ADMIN_PASSWORD', 'secret-from-env');

        $config = require base_path('config/argos.php');

        $this->assertSame('secret-from-env', $config['admin_password']);
    }
}
