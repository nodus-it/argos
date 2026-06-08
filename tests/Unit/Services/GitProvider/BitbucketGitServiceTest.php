<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\Bitbucket\Requests\CommentOnPullRequest;
use App\Integrations\Bitbucket\Requests\UpdatePullRequest;
use App\Services\GitProvider\BitbucketGitService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class BitbucketGitServiceTest extends TestCase
{
    public function test_get_default_branch_returns_api_value(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget' => MockResponse::make([
                'full_name' => 'acme/widget',
                'mainbranch' => ['name' => 'develop', 'type' => 'branch'],
            ]),
        ]);

        $branch = (new BitbucketGitService('user:app_password'))->getDefaultBranch('acme/widget');

        $this->assertSame('develop', $branch);
    }

    public function test_get_default_branch_returns_null_for_invalid_input(): void
    {
        $service = new BitbucketGitService('user:app_password');

        $this->assertNull($service->getDefaultBranch(''));
        $this->assertNull($service->getDefaultBranch('only-name-no-slash'));
    }

    public function test_get_default_branch_returns_null_on_http_failure(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/*' => MockResponse::make(['type' => 'error'], 404),
        ]);

        $branch = (new BitbucketGitService('user:app_password'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_mainbranch_missing(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget' => MockResponse::make([
                'full_name' => 'acme/widget',
            ]),
        ]);

        $branch = (new BitbucketGitService('user:app_password'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_get_repo_options_returns_keyed_array(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make([
                'values' => [
                    ['workspace' => ['slug' => 'acme']],
                ],
            ]),
            'https://api.bitbucket.org/2.0/repositories/acme*' => MockResponse::make([
                'values' => [
                    ['full_name' => 'acme/alpha'],
                    ['full_name' => 'acme/beta'],
                ],
            ]),
        ]);

        $options = (new BitbucketGitService('user:pass'))->getRepoOptions();

        $this->assertSame(['acme/alpha' => 'acme/alpha', 'acme/beta' => 'acme/beta'], $options);
    }

    public function test_list_repositories_merges_repos_across_workspaces(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make([
                'values' => [
                    ['workspace' => ['slug' => 'acme']],
                    ['workspace' => ['slug' => 'globex']],
                ],
            ]),
            'https://api.bitbucket.org/2.0/repositories/acme*' => MockResponse::make([
                'values' => [['full_name' => 'acme/alpha']],
            ]),
            'https://api.bitbucket.org/2.0/repositories/globex*' => MockResponse::make([
                'values' => [['full_name' => 'globex/beta']],
            ]),
        ]);

        $repos = (new BitbucketGitService('user:pass'))->listRepositories();

        $this->assertCount(2, $repos);
        $this->assertSame('acme/alpha', $repos[0]['full_name']);
        $this->assertSame('globex/beta', $repos[1]['full_name']);
    }

    public function test_list_repositories_skips_workspace_entries_without_slug(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make([
                'values' => [
                    ['workspace' => ['slug' => '']],
                    ['workspace' => []],
                    ['workspace' => ['slug' => 'acme']],
                ],
            ]),
            'https://api.bitbucket.org/2.0/repositories/acme*' => MockResponse::make([
                'values' => [['full_name' => 'acme/alpha']],
            ]),
        ]);

        $repos = (new BitbucketGitService('user:pass'))->listRepositories();

        $this->assertCount(1, $repos);
        $this->assertSame('acme/alpha', $repos[0]['full_name']);
    }

    public function test_get_branch_options_returns_keyed_array(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/refs/branches*' => MockResponse::make([
                'values' => [
                    ['name' => 'main'],
                    ['name' => 'develop'],
                ],
            ]),
        ]);

        $options = (new BitbucketGitService('user:pass'))->getBranchOptions('acme/widget');

        $this->assertSame(['main' => 'main', 'develop' => 'develop'], $options);
    }

    public function test_get_branch_options_returns_empty_for_invalid_owner_repo(): void
    {
        $service = new BitbucketGitService('user:pass');

        $this->assertSame([], $service->getBranchOptions(''));
        $this->assertSame([], $service->getBranchOptions('no-slash'));
    }

    public function test_uses_basic_auth_for_pat_token(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make(['values' => []]),
        ]);

        (new BitbucketGitService('myuser:mysecret'))->listRepositories();

        Saloon::assertSent(fn (Request $request, $response): bool => str_starts_with((string) $response->getPendingRequest()->headers()->get('Authorization'), 'Basic '));
    }

    public function test_uses_bearer_auth_for_oauth_token(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make(['values' => []]),
        ]);

        (new BitbucketGitService('oauthtokenwithoutcolon'))->listRepositories();

        Saloon::assertSent(fn (Request $request, $response): bool => str_starts_with((string) $response->getPendingRequest()->headers()->get('Authorization'), 'Bearer '));
    }

    public function test_comment_on_pull_request_wraps_body_in_content_raw(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/pullrequests/3/comments' => MockResponse::make([
                'id' => 9001,
            ]),
        ]);

        $response = (new BitbucketGitService('user:pass'))->commentOnPullRequest('acme', 'widget', 3, 'hello');

        $this->assertSame(9001, $response['id']);
        Saloon::assertSent(function (Request $request): bool {
            $body = $request instanceof CommentOnPullRequest ? $request->body()->all() : [];

            return $request instanceof CommentOnPullRequest
                && str_contains($request->resolveEndpoint(), '/pullrequests/3/comments')
                && ($body['content']['raw'] ?? null) === 'hello';
        });
    }

    public function test_update_pull_request_puts_title_and_description(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/pullrequests/3' => MockResponse::make(['id' => 3]),
        ]);

        (new BitbucketGitService('user:pass'))->updatePullRequest('acme', 'widget', 3, 'new title', 'new body');

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request instanceof UpdatePullRequest ? $request->body()->all() : [];

            return $request instanceof UpdatePullRequest
                && $response->getPendingRequest()->getMethod()->value === 'PUT'
                && str_ends_with($request->resolveEndpoint(), '/pullrequests/3')
                && ($body['title'] ?? null) === 'new title'
                && ($body['description'] ?? null) === 'new body';
        });
    }
}
