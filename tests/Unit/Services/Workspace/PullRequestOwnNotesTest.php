<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspace;

use App\Services\GitProvider\PullRequestDetails;
use App\Services\Workspace\PullRequestService;
use PHPUnit\Framework\TestCase;

class PullRequestOwnNotesTest extends TestCase
{
    public function test_argos_shipping_notes_alone_are_no_review(): void
    {
        $own = ['body' => "New commits pushed by Ada:\n\n- chore: set up\n\n".PullRequestService::ARGOS_MARKER];
        $details = new PullRequestDetails(state: 'open', reviewState: 'commented', comments: [$own]);

        $this->assertNull(PullRequestService::withoutOwnNotes($details)->reviewState);
        $this->assertSame([$own], PullRequestService::withoutOwnNotes($details)->comments);
    }

    public function test_a_person_commenting_still_counts(): void
    {
        $details = new PullRequestDetails(state: 'open', reviewState: 'commented', comments: [['body' => 'Looks off here']]);

        $this->assertSame('commented', PullRequestService::withoutOwnNotes($details)->reviewState);
    }
}
