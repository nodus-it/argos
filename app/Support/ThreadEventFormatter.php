<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Display helpers for workspace thread events: one-line tool summaries and
 * safe Markdown for assistant text (raw HTML from the model is escaped).
 */
final class ThreadEventFormatter
{
    /**
     * @param  array<string, mixed>|mixed  $input
     */
    public static function toolSummary(string $tool, mixed $input): string
    {
        if (! is_array($input)) {
            return '';
        }

        $value = match (true) {
            isset($input['command']) => $input['command'],
            isset($input['file_path']) => $input['file_path'],
            isset($input['path']) => $input['path'],
            isset($input['pattern']) => $input['pattern'],
            isset($input['url']) => $input['url'],
            isset($input['query']) => $input['query'],
            isset($input['description']) => $input['description'],
            default => '',
        };

        $text = is_string($value) ? $value : (string) json_encode($value);
        $text = str_replace('/workspace/', '', $text);

        return Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 140);
    }

    public static function markdown(string $text): HtmlString
    {
        return new HtmlString(Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }

    /** Pretty JSON for the "show input" toggle. */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
