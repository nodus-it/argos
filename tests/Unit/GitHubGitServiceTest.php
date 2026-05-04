<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GitHub\GitHubGitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubGitServiceTest extends TestCase
{
    public function test_get_default_branch_returns_api_value(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget' => Http::response([
                'name' => 'widget',
                'default_branch' => 'develop',
            ]),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertSame('develop', $branch);
    }

    public function test_get_default_branch_returns_null_for_invalid_input(): void
    {
        $service = new GitHubGitService('tok');

        $this->assertNull($service->getDefaultBranch(''));
        $this->assertNull($service->getDefaultBranch('only-name-no-slash'));
    }

    public function test_get_default_branch_returns_null_on_http_failure(): void
    {
        Http::fake([
            'https://api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_field_missing(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget' => Http::response(['name' => 'widget']),
        ]);

        $branch = (new GitHubGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_comment_on_pull_request_posts_to_issues_endpoint(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget/issues/42/comments' => Http::response([
                'id' => 9001, 'body' => 'hello',
            ]),
        ]);

        $response = (new GitHubGitService('tok'))->commentOnPullRequest('acme', 'widget', 42, 'hello');

        $this->assertSame(9001, $response['id']);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/issues/42/comments')
                && ($request->data()['body'] ?? null) === 'hello';
        });
    }
}
