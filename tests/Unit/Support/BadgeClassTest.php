<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BadgeClass;
use Tests\TestCase;

class BadgeClassTest extends TestCase
{
    public function test_maps_filament_colours_to_badge_classes(): void
    {
        $this->assertSame('badge-success', BadgeClass::for('success'));
        $this->assertSame('badge-running', BadgeClass::for('warning'));
        $this->assertSame('badge-failed', BadgeClass::for('danger'));
        $this->assertSame('badge-draft', BadgeClass::for('gray'));
    }

    public function test_unknown_colour_falls_back_to_draft(): void
    {
        $this->assertSame('badge-draft', BadgeClass::for('chartreuse'));
    }
}
