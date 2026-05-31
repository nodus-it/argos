<?php

declare(strict_types=1);

namespace Tests\Feature;

use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use Filament\Facades\Filament;
use Tests\TestCase;

class DeveloperLoginsTest extends TestCase
{
    public function test_admin_panel_registers_developer_logins_plugin(): void
    {
        $plugin = Filament::getPanel('admin')->getPlugin('filament-developer-logins');

        $this->assertInstanceOf(FilamentDeveloperLoginsPlugin::class, $plugin);
        $this->assertSame(['Admin' => 'admin@argos.local'], $plugin->getUsers());
        $this->assertFalse($plugin->getSwitchable());
    }

    public function test_developer_logins_are_disabled_outside_local(): void
    {
        // The panel is built once at boot under the testing environment, so the
        // gate must have resolved to disabled — proving it never renders the
        // password-free login buttons in staging/production.
        $plugin = Filament::getPanel('admin')->getPlugin('filament-developer-logins');

        $this->assertFalse($plugin->getEnabled());
    }

    public function test_gate_expression_enables_only_in_local_environment(): void
    {
        $this->app['env'] = 'local';
        $local = FilamentDeveloperLoginsPlugin::make()->enabled($this->app->environment('local'));
        $this->assertTrue($local->getEnabled());

        $this->app['env'] = 'production';
        $production = FilamentDeveloperLoginsPlugin::make()->enabled($this->app->environment('local'));
        $this->assertFalse($production->getEnabled());
    }
}
