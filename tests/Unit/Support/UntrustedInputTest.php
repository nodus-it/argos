<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\UntrustedInput;
use Tests\TestCase;

class UntrustedInputTest extends TestCase
{
    public function test_wraps_text_in_markers_with_matching_nonce(): void
    {
        $wrapped = app(UntrustedInput::class)->wrapText('Build the thing.', true);

        $this->assertStringContainsString('[BEGIN UNTRUSTED ISSUE', $wrapped);
        $this->assertStringContainsString('[END UNTRUSTED ISSUE', $wrapped);
        $this->assertStringContainsString('Build the thing.', $wrapped);

        // Same unguessable nonce in both markers (breakout protection).
        $this->assertSame(1, preg_match('/ref:([0-9a-f]{16})]\n/', $wrapped, $begin));
        $this->assertSame(1, preg_match('/\[END UNTRUSTED ISSUE · ref:([0-9a-f]{16})]$/', $wrapped, $end));
        $this->assertSame($begin[1], $end[1]);
    }

    public function test_marks_external_text_as_untrusted(): void
    {
        $wrapped = app(UntrustedInput::class)->wrapText('x', true);

        $this->assertStringContainsString('source: external issue tracker', $wrapped);
    }

    public function test_marks_internal_text_as_operator_entered(): void
    {
        $wrapped = app(UntrustedInput::class)->wrapText('x', false, 'NOTE');

        $this->assertStringContainsString('source: operator', $wrapped);
        $this->assertStringContainsString('[BEGIN UNTRUSTED NOTE', $wrapped);
    }
}
