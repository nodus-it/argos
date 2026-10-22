<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * Display helpers for workspace task events: one-line tool summaries and
 * safe Markdown for assistant text (raw HTML from the model is escaped).
 */
final class TaskEventFormatter
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

    /**
     * Markdown as HTML; links leave Argos in a new tab, bare URLs become
     * links. `$breaks` keeps single line breaks (what people type in chat).
     */
    public static function markdown(string $text, bool $breaks = false): HtmlString
    {
        return new HtmlString(Str::markdown($text, [
            'renderer' => ['soft_break' => $breaks ? "<br>\n" : "\n"],
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)])),
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [new AutolinkExtension, new ExternalLinkExtension]));
    }

    /** Pretty JSON for the "show input" toggle. */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
