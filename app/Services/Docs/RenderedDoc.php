<?php

declare(strict_types=1);

namespace App\Services\Docs;

/**
 * The output of DocsRenderer for one page: the resolved title, the rendered
 * HTML body (leading H1 stripped — the page chrome shows the title), and the
 * table of contents (h2/h3 headings with their anchor slugs).
 */
final readonly class RenderedDoc
{
    /**
     * @param  list<array{level: int, text: string, slug: string}>  $toc
     */
    public function __construct(
        public string $title,
        public string $html,
        public array $toc,
    ) {}
}
