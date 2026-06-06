<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the tail of a (potentially large) log file cheaply.
 *
 * The worker mirrors the full stream-json into a task's `.bg.log`, which can
 * grow to many megabytes during a long phase. The live view only needs the
 * recent tail, so we seek to the last chunk instead of loading the whole file
 * on every poll.
 */
class LogTail
{
    /** Bytes read from the end of the file. Bounds per-poll parse cost. */
    private const TAIL_BYTES = 512 * 1024;

    /**
     * Return the last ~512 KB of $path as a string, dropping a leading partial
     * line. Returns '' when the file does not exist.
     */
    public static function read(string $path, int $maxBytes = self::TAIL_BYTES): string
    {
        if (! is_file($path)) {
            return '';
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $offset = max(0, $size - $maxBytes);
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            return '';
        }

        // Drop the (likely partial) first line when we seeked into the file.
        if ($offset > 0) {
            $nl = strpos($content, "\n");
            $content = $nl === false ? '' : substr($content, $nl + 1);
        }

        return $content;
    }
}
