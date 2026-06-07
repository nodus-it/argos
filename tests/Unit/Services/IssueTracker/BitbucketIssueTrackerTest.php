<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Integrations\Bitbucket\Requests\CloseIssue;
use App\Services\IssueTracker\BitbucketIssueTracker;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class BitbucketIssueTrackerTest extends TestCase
{
    public function test_close_issue_sets_state_resolved(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/issues/7' => MockResponse::make([], 200),
        ]);

        (new BitbucketIssueTracker('user:app-pw'))->closeIssue('acme', 'widget', 7);

        Saloon::assertSent(function (Request $r, $response): bool {
            $body = $r instanceof CloseIssue ? $r->body()->all() : [];

            return $r instanceof CloseIssue
                && $response->getPendingRequest()->getMethod()->value === 'PUT'
                && str_contains($r->resolveEndpoint(), '/issues/7')
                && ($body['state'] ?? null) === 'resolved';
        });
    }

    public function test_list_references_maps_full_names_to_ref_options(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories*' => MockResponse::make([
                'values' => [
                    ['full_name' => 'acme/widget'],
                    ['full_name' => 'acme/gadget'],
                ],
            ]),
        ]);

        $refs = (new BitbucketIssueTracker('user:app-pw'))->listReferences();

        $this->assertSame([
            'acme/widget' => 'acme/widget',
            'acme/gadget' => 'acme/gadget',
        ], $refs);
    }

    public function test_list_references_returns_empty_when_access_forbidden(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories*' => MockResponse::make('', 403),
        ]);

        $this->assertSame([], (new BitbucketIssueTracker('token'))->listReferences());
    }
}
