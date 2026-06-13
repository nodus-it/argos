<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use App\Services\Docs\DocsRenderer;
use Tests\TestCase;

class DocsRendererTest extends TestCase
{
    private function render(string $markdown)
    {
        return app(DocsRenderer::class)->renderMarkdown($markdown);
    }

    public function test_headings_get_stable_anchor_ids(): void
    {
        $doc = $this->render("## Reverse Proxy\n\nText.");

        $this->assertStringContainsString('id="reverse-proxy"', $doc->html);
    }

    public function test_toc_collects_h2_and_h3_only(): void
    {
        $doc = $this->render("# Title\n\n## One\n\n### Sub\n\n#### Deep\n\n## Two");

        $this->assertSame([
            ['level' => 2, 'text' => 'One', 'slug' => 'one'],
            ['level' => 3, 'text' => 'Sub', 'slug' => 'sub'],
            ['level' => 2, 'text' => 'Two', 'slug' => 'two'],
        ], $doc->toc);
    }

    public function test_duplicate_headings_get_unique_anchors(): void
    {
        $doc = $this->render("## Setup\n\n## Setup");

        $this->assertStringContainsString('id="setup"', $doc->html);
        $this->assertStringContainsString('id="setup-2"', $doc->html);
        $this->assertSame('setup-2', $doc->toc[1]['slug']);
    }

    public function test_leading_h1_becomes_the_title_and_is_stripped_from_the_body(): void
    {
        $doc = $this->render("# Real Title\n\nBody.");

        $this->assertSame('Real Title', $doc->title);
        $this->assertStringNotContainsString('<h1', $doc->html);
        $this->assertStringContainsString('Body.', $doc->html);
    }

    public function test_title_is_empty_when_there_is_no_leading_h1(): void
    {
        $doc = $this->render("## Just a section\n\nBody.");

        $this->assertSame('', $doc->title);
    }

    public function test_internal_md_link_is_rewritten_to_in_app_route(): void
    {
        $doc = $this->render('See [setup](SETUP.md#reverse-proxy).');

        $this->assertStringContainsString(
            route('filament.admin.pages.docs', ['slug' => 'setup']).'#reverse-proxy',
            $doc->html,
        );
    }

    public function test_app_url_placeholder_is_substituted_with_the_real_url(): void
    {
        config(['app.url' => 'https://argos.example.com/']);

        $doc = $this->render('Connect your client to `${APP_URL}/mcp`.');

        $this->assertStringContainsString('https://argos.example.com/mcp', $doc->html);
        $this->assertStringNotContainsString('${APP_URL}', $doc->html);
    }

    public function test_external_and_repo_only_links_are_left_untouched(): void
    {
        $doc = $this->render('[ext](https://example.com) [repo](CONTRIBUTING.md)');

        $this->assertStringContainsString('https://example.com', $doc->html);
        // Not in the manifest → stays a plain repo link, not rewritten.
        $this->assertStringContainsString('CONTRIBUTING.md', $doc->html);
    }
}
