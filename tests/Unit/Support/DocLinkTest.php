<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DocLink;
use App\Support\DocsLinkAction;
use Filament\Actions\Action;
use Tests\TestCase;

class DocLinkTest extends TestCase
{
    public function test_url_points_at_the_docs_route(): void
    {
        $this->assertSame(
            route('filament.admin.pages.docs', ['slug' => 'setup']),
            DocLink::url('setup'),
        );
    }

    public function test_url_appends_an_anchor(): void
    {
        $this->assertSame(
            route('filament.admin.pages.docs', ['slug' => 'setup']).'#reverse-proxy',
            DocLink::url('setup', 'reverse-proxy'),
        );
    }

    public function test_docs_link_action_targets_the_page(): void
    {
        $action = DocsLinkAction::make('oauth');

        $this->assertInstanceOf(Action::class, $action);
        $this->assertSame('docs_oauth', $action->getName());
        $this->assertSame(DocLink::url('oauth'), $action->getUrl());
    }

    public function test_docs_link_action_parses_an_anchor_reference(): void
    {
        $action = DocsLinkAction::make('setup#reverse-proxy');

        $this->assertSame(DocLink::url('setup', 'reverse-proxy'), $action->getUrl());
    }

    public function test_docs_link_action_opens_in_a_new_tab(): void
    {
        // Doc links must not pull the user out of their current context.
        $this->assertTrue(DocsLinkAction::make('oauth')->shouldOpenUrlInNewTab());
    }
}
