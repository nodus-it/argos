<?php

declare(strict_types=1);

namespace Tests\Feature\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\IssueFilterMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueFilterMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function binding(array $filters): TaskProviderBinding
    {
        return TaskProviderBinding::factory()->create(['filters' => $filters]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function issue(array $payload): ExternalIssue
    {
        return ExternalIssue::fromProvider($payload, TaskProviderKind::GitHub);
    }

    public function test_passes_when_no_filters_are_set(): void
    {
        $matcher = new IssueFilterMatcher;

        $this->assertTrue($matcher->passes($this->issue(['state' => 'closed']), $this->binding([])));
    }

    public function test_state_filter_must_match(): void
    {
        $matcher = new IssueFilterMatcher;
        $binding = $this->binding(['state' => 'open']);

        $this->assertTrue($matcher->passes($this->issue(['state' => 'open']), $binding));
        $this->assertFalse($matcher->passes($this->issue(['state' => 'closed']), $binding));
    }

    public function test_labels_use_or_semantics(): void
    {
        $matcher = new IssueFilterMatcher;
        $binding = $this->binding(['labels' => ['bug', 'urgent']]);

        // At least one configured label present → passes.
        $this->assertTrue($matcher->passes($this->issue(['labels' => [['name' => 'urgent'], ['name' => 'wontfix']]]), $binding));
        // None present → fails.
        $this->assertFalse($matcher->passes($this->issue(['labels' => [['name' => 'wontfix']]]), $binding));
    }

    public function test_labels_accept_plain_string_lists(): void
    {
        $matcher = new IssueFilterMatcher;
        $binding = $this->binding(['labels' => ['bug']]);

        $this->assertTrue($matcher->passes($this->issue(['labels' => ['bug', 'docs']]), $binding));
    }
}
