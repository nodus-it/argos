<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** The app's stylesheet is scoped to the panel's body class — renaming the panel must not leave it unstyled. */
class AppStylesTest extends TestCase
{
    public function test_the_stylesheet_targets_the_panel(): void
    {
        $css = (string) file_get_contents(resource_path('css/workspace.css'));

        $this->assertStringContainsString('.fi-panel-'.Filament::getPanel('app')->getId(), $css);
        $this->assertDoesNotMatchRegularExpression('/\.fi-panel-(?!'.preg_quote(Filament::getPanel('app')->getId(), '/').'\b)[a-z]+/', $css);
    }
}
