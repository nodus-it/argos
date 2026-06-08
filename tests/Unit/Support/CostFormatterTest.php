<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CostFormatter;
use Tests\TestCase;

class CostFormatterTest extends TestCase
{
    public function test_usd_renders_four_decimals_with_dollar_sign(): void
    {
        $this->assertSame('$0.0023', CostFormatter::usd(0.0023));
        $this->assertSame('$1.5000', CostFormatter::usd(1.5));
    }

    public function test_tokens_renders_grouped_with_suffix(): void
    {
        $this->assertSame('1,234 tok', CostFormatter::tokens(1234));
        $this->assertSame('0 tok', CostFormatter::tokens(0));
    }
}
