<?php

declare(strict_types=1);

namespace Tests\Feature\Git;

use App\Integrations\GitHub\Requests\ListRepositories;
use App\Services\Git\RepositoryFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class RepositoryFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_repo_options_map_full_names(): void
    {
        Saloon::fake([
            'https://api.github.com/user/repos*' => MockResponse::make([
                ['full_name' => 'acme/widget'],
                ['full_name' => 'acme/gadget'],
            ]),
        ]);

        $options = app(RepositoryFetcher::class)->repoOptions('github', 'tok', '', 'fetcher_test_repos_1');

        $this->assertSame(['acme/widget' => 'acme/widget', 'acme/gadget' => 'acme/gadget'], $options);
    }

    public function test_options_are_cached_under_the_key(): void
    {
        Saloon::fake([
            'https://api.github.com/user/repos*' => MockResponse::make([['full_name' => 'acme/widget']]),
        ]);

        $fetcher = app(RepositoryFetcher::class);
        $fetcher->repoOptions('github', 'tok', '', 'fetcher_test_repos_2');
        $fetcher->repoOptions('github', 'tok', '', 'fetcher_test_repos_2');

        // Second call is served from cache — only one request hit the provider.
        Saloon::assertSentCount(1);
    }

    public function test_provider_failure_degrades_to_empty_list(): void
    {
        Saloon::fake([
            'https://api.github.com/user/repos*' => MockResponse::make(['message' => 'boom'], 500),
        ]);

        $options = app(RepositoryFetcher::class)->repoOptions('github', 'tok', '', 'fetcher_test_repos_3');

        $this->assertSame([], $options);
    }

    public function test_default_branch_returns_null_on_failure(): void
    {
        Saloon::fake([
            ListRepositories::class => MockResponse::make([], 200),
            'https://api.github.com/repos/*' => MockResponse::make(['message' => 'boom'], 500),
        ]);

        $branch = app(RepositoryFetcher::class)->defaultBranch('github', 'tok', '', 'acme/widget');

        $this->assertNull($branch);
    }
}
