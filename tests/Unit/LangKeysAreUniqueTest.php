<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A key written twice in one array of a lang file silently loses the first
 * value (PHP keeps the last) — e.g. a label replaced by a plural string.
 */
class LangKeysAreUniqueTest extends TestCase
{
    public function test_no_lang_file_repeats_a_key_within_one_array(): void
    {
        $duplicates = [];
        foreach (glob(dirname(__DIR__, 2).'/lang/*/*.php') ?: [] as $file) {
            $stack = [[]];
            $path = [];
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match("/^\\s*'([^']+)'\\s*=>\\s*(\\[)?/", $line, $m) === 1) {
                    $key = $m[1];
                    if (isset($stack[array_key_last($stack)][$key])) {
                        $duplicates[] = basename(dirname($file)).'/'.basename($file).':'.($number + 1).' '.implode('.', [...$path, $key]);
                    }
                    $stack[array_key_last($stack)][$key] = true;
                    if (($m[2] ?? '') === '[' && preg_match('/\\]\\s*,?\\s*$/', $line) !== 1) {
                        $stack[] = [];
                        $path[] = $key;
                    }
                } elseif (preg_match('/^\\s*\\],?\\s*$/', $line) === 1 && count($stack) > 1) {
                    array_pop($stack);
                    array_pop($path);
                }
            }
        }

        $this->assertSame([], $duplicates);
    }
}
