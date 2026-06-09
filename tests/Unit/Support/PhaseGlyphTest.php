<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PhaseGlyph;
use Tests\TestCase;

class PhaseGlyphTest extends TestCase
{
    public function test_maps_known_phases_to_icons_and_labels(): void
    {
        $this->assertSame('heroicon-o-light-bulb', PhaseGlyph::icon('concept'));
        $this->assertSame('heroicon-o-code-bracket', PhaseGlyph::icon('implement'));
        $this->assertSame('Push', PhaseGlyph::label('push'));
    }

    public function test_unknown_phase_falls_back_to_draft(): void
    {
        $this->assertSame(PhaseGlyph::icon('draft'), PhaseGlyph::icon('nonsense'));
        $this->assertSame(PhaseGlyph::label('draft'), PhaseGlyph::label('nonsense'));
    }
}
