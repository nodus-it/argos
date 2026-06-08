<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\GitHub\Requests\CommentOnPullRequest;
use App\Integrations\GitHub\Requests\GetRepository;
use App\Integrations\GitHub\Requests\UpdatePullRequest;
use App\Services\GitProvider\GitHubGitService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GitHubGitServiceTest extends TestCase
{
    public function test_get_default_branch_returns_api_value(): void
    {
        Saloon::fake([
            GetRepository::class => MockResponse::make([
                'name' => 'widget',
                'default_branch' => 'develop',
            ]),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertSame('develop', $branch);
    }

    public function test_get_default_branch_returns_null_for_invalid_input(): void
    {
        Saloon::fake([]);

        $service = new GitHubGitService('tok');

        $this->assertNull($service->getDefaultBranch(''));
        $this->assertNull($service->getDefaultBranch('only-name-no-slash'));
    }

    public function test_get_default_branch_returns_null_on_http_failure(): void
    {
        Saloon::fake([
            GetRepository::class => MockResponse::make(['message' => 'Not Found'], 404),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_field_missing(): void
    {
        Saloon::fake([
            GetRepository::class => MockResponse::make(['name' => 'widget']),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_comment_on_pull_request_posts_to_issues_endpoint(): void
    {
        Saloon::fake([
            CommentOnPullRequest::class => MockResponse::make(['id' => 9001, 'body' => 'hello']),
        ]);

        $response = (new GitHubGitService('tok'))->commentOnPullRequest('acme', 'widget', 42, 'hello');

        $this->assertSame(9001, $response['id']);
        // The request class fixes method (POST) and endpoint
        // (/issues/{id}/comments), so instanceof proves both; we only need to
        // verify the body the service handed to it.
        Saloon::assertSent(function (Request $request): bool {
            return $request instanceof CommentOnPullRequest
                && $request->resolveEndpoint() === '/repos/acme/widget/issues/42/comments'
                && ($request->body()->all()['body'] ?? null) === 'hello';
        });
    }

    public function test_update_pull_request_patches_title_and_body(): void
    {
        Saloon::fake([
            UpdatePullRequest::class => MockResponse::make(['number' => 42]),
        ]);

        (new GitHubGitService('tok'))->updatePullRequest('acme', 'widget', 42, 'new title', 'new body');

        Saloon::assertSent(function (Request $request): bool {
            $body = $request instanceof UpdatePullRequest ? $request->body()->all() : [];

            return $request instanceof UpdatePullRequest
                && $request->resolveEndpoint() === '/repos/acme/widget/pulls/42'
                && ($body['title'] ?? null) === 'new title'
                && ($body['body'] ?? null) === 'new body';
        });
    }
}
