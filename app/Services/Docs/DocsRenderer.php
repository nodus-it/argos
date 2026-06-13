<?php

declare(strict_types=1);

namespace App\Services\Docs;

use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders a documentation Markdown file to HTML for the in-app viewer. Walks the
 * parsed AST to (1) give every heading a stable anchor id, (2) collect an h2/h3
 * table of contents, (3) rewrite internal `*.md` links to in-app doc routes, and
 * (4) strip a leading H1 (the page chrome shows the title). Source is trusted
 * repo content (docs/ in the app image), not user input.
 */
class DocsRenderer
{
    public function __construct(private readonly DocManifest $manifest) {}

    /** Render a manifest doc file. The title is the doc's leading H1 (or empty). */
    public function render(string $file): RenderedDoc
    {
        $path = $this->manifest->absolutePath($file);
        $markdown = is_file($path) ? (string) file_get_contents($path) : '';

        return $this->renderMarkdown($markdown);
    }

    /** The core: render raw Markdown (kept separate so it is unit-testable). */
    public function renderMarkdown(string $markdown): RenderedDoc
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $document = (new MarkdownParser($environment))->parse($markdown);

        $toc = [];
        $usedSlugs = [];
        $leadingH1 = null;

        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading) {
                $text = $this->plainText($node);
                $slug = $this->uniqueSlug($text, $usedSlugs);
                $node->data->set('attributes/id', $slug);

                if ($leadingH1 === null && $node->getLevel() === 1
                    && $node->parent() === $document && $node->previous() === null) {
                    $leadingH1 = $node;
                } elseif ($node->getLevel() >= 2 && $node->getLevel() <= 3) {
                    $toc[] = ['level' => $node->getLevel(), 'text' => $text, 'slug' => $slug];
                }
            } elseif ($node instanceof Link) {
                $node->setUrl($this->rewriteUrl($node->getUrl()));
            }
        }

        $resolvedTitle = $leadingH1 !== null ? $this->plainText($leadingH1) : '';

        // Drop the leading H1 so it isn't shown twice (page header + body).
        $leadingH1?->detach();

        $html = (string) (new HtmlRenderer($environment))->renderDocument($document);

        return new RenderedDoc($resolvedTitle, $html, $toc);
    }

    /** Concatenated literal text of every descendant Text node. */
    private function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getLiteral();
            }
        }

        return trim($text);
    }

    /**
     * Slugify a heading, disambiguating repeats with a numeric suffix so anchors
     * stay unique within the page.
     *
     * @param  array<string, true>  $used
     */
    private function uniqueSlug(string $text, array &$used): string
    {
        $base = Str::slug($text) ?: 'section';
        $slug = $base;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $base.'-'.$n++;
        }
        $used[$slug] = true;

        return $slug;
    }

    /**
     * Rewrite a relative sibling-doc link (`setup.md#x`) to its in-app route.
     * External URLs, anchors, mailto, and docs outside the manifest pass through.
     */
    private function rewriteUrl(string $url): string
    {
        if (str_contains($url, '://') || str_starts_with($url, '#') || str_starts_with($url, 'mailto:')) {
            return $url;
        }

        if (preg_match('~^(?:\./)?([A-Za-z0-9._/-]+)\.md(#\S*)?$~', $url, $m) !== 1) {
            return $url;
        }

        $slug = $this->manifest->slugForFile(basename($m[1]).'.md');
        if ($slug === null) {
            return $url;
        }

        return route('filament.admin.pages.docs', ['slug' => $slug]).($m[2] ?? '');
    }
}
