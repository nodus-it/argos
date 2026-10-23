<?php

declare(strict_types=1);

// A full, uncached run outgrew 512M. Raise the floor, never lower a higher
// --memory-limit (or -1) given on the command line.
$current = ini_get('memory_limit');
if ($current !== '-1' && (int) $current < 2048 && ! str_ends_with((string) $current, 'G')) {
    ini_set('memory_limit', '2G');
}
