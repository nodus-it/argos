<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Display formatting for Claude usage figures. Costs render with four decimals
 * because per-run amounts are routinely below $0.01, where two decimals would
 * collapse to $0.00.
 */
class CostFormatter
{
    public static function usd(float $cost): string
    {
        return '$'.number_format($cost, 4);
    }

    public static function tokens(int $tokens): string
    {
        return number_format($tokens).' tok';
    }
}
