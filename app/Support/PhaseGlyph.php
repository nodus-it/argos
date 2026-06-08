<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Timeline glyphs for the workflow phase rail and thread.
 *
 * The keys are UI phase strings, a superset of the Phase enum: they also
 * include 'draft' (a workflow status) and 'review' (a UI-only step), which is
 * why this mapping lives here rather than on Phase::icon(). Unknown keys fall
 * back to the 'draft' glyph.
 */
class PhaseGlyph
{
    private const ICONS = [
        'draft' => 'heroicon-o-document-text',
        'concept' => 'heroicon-o-light-bulb',
        'implement' => 'heroicon-o-code-bracket',
        'push' => 'heroicon-o-arrow-up-tray',
        'review' => 'heroicon-o-chat-bubble-left-right',
        'respond' => 'heroicon-o-chat-bubble-left-right',
    ];

    private const LABELS = [
        'draft' => 'Draft',
        'concept' => 'Concept',
        'implement' => 'Implement',
        'push' => 'Push',
        'review' => 'Review',
        'respond' => 'Respond',
    ];

    public static function icon(string $phase): string
    {
        return self::ICONS[$phase] ?? self::ICONS['draft'];
    }

    public static function label(string $phase): string
    {
        return self::LABELS[$phase] ?? self::LABELS['draft'];
    }
}
