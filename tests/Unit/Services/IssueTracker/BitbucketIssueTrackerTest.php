<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Services\IssueTracker\BitbucketIssueTracker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitbucketIssueTrackerTest extends TestCase
{
    public function test_close_issue_sets_state_resolved(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/issues/7' => Http::response([], 200),
        ]);

        (new BitbucketIssueTracker('user:app-pw'))->closeIssue('acme', 'widget', 7);

        Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
            && str_starts_with($r->url(), 'https://api.bitbucket.org/2.0/repositories/acme/widget/issues/7')
            && $r['state'] === 'resolved');
    }

    public function test_list_references_maps_full_names_to_ref_options(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories*' => Http::response([
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
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories*' => Http::response(null, 403),
        ]);

        $this->assertSame([], (new BitbucketIssueTracker('token'))->listReferences());
    }
}
