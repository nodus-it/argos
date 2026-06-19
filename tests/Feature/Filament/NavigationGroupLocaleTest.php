<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * The panel is configured at provider-boot (before SetUserLocale runs), so the
 * navigation group labels must be lazy closures — otherwise they freeze to the
 * boot locale and, in another locale, no longer match the render-time
 * getNavigationGroup() strings (the groups split / a stray English "Help"
 * appears and the order breaks). This guards the lazy resolution + order.
 */
class NavigationGroupLocaleTest extends TestCase
{
    /** @return list<string> */
    private function groupLabels(): array
    {
        return array_values(array_map(
            fn ($group): string => (string) $group->getLabel(),
            Filament::getPanel('admin')->getNavigationGroups(),
        ));
    }

    public function test_groups_resolve_in_order_in_english(): void
    {
        $this->app->setLocale('en');

        $this->assertSame(['Worker', 'Configuration', 'Help'], $this->groupLabels());
    }

    public function test_groups_resolve_in_order_in_german(): void
    {
        $this->app->setLocale('de');

        // Help stays last (and is "Hilfe", not a stray English "Help").
        $this->assertSame(['Worker', 'Konfiguration', 'Hilfe'], $this->groupLabels());
    }
}
