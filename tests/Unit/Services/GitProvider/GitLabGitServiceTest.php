<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\GitLab\Requests\CommentOnMergeRequest;
use App\Integrations\GitLab\Requests\CreateMergeRequest;
use App\Integrations\GitLab\Requests\UpdateMergeRequest;
use App\Services\GitProvider\GitLabGitService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GitLabGitServiceTest extends TestCase
{
    public function test_list_repositories_uses_bearer_auth(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects*' => MockResponse::make([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
            ]),
        ]);

        (new GitLabGitService('glpat-test'))->listRepositories();

        Saloon::assertSent(fn (Request $request, $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer glpat-test');
    }

    public function test_list_repositories_returns_array(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects*' => MockResponse::make([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
                ['id' => 2, 'path_with_namespace' => 'acme/other'],
            ]),
        ]);

        $result = (new GitLabGitService('tok'))->listRepositories();

        $this->assertCount(2, $result);
        $this->assertSame('acme/widget', $result[0]['path_with_namespace']);
    }

    public function test_list_branches_encodes_project_path(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/repository/branches*' => MockResponse::make([
                ['name' => 'main'],
                ['name' => 'develop'],
            ]),
        ]);

        $result = (new GitLabGitService('tok'))->listBranches('acme', 'widget');

        $this->assertCount(2, $result);
        $this->assertSame('main', $result[0]['name']);
    }

    public function test_create_pull_request_creates_merge_request(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests' => MockResponse::make([
                'id' => 1,
                'web_url' => 'https://gitlab.com/acme/widget/-/merge_requests/1',
            ], 201),
        ]);

        $result = (new GitLabGitService('tok'))->createPullRequest(
            'acme', 'widget', 'My MR', 'Description', 'feature', 'main'
        );

        $this->assertSame('https://gitlab.com/acme/widget/-/merge_requests/1', $result['web_url']);

        Saloon::assertSent(function (Request $request): bool {
            $body = $request instanceof CreateMergeRequest ? $request->body()->all() : [];

            return ($body['source_branch'] ?? null) === 'feature' && ($body['target_branch'] ?? null) === 'main';
        });
    }

    public function test_get_repo_options_returns_path_with_namespace_map(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects*' => MockResponse::make([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
                ['id' => 2, 'path_with_namespace' => 'acme/other'],
                ['id' => 3],
            ]),
        ]);

        $options = (new GitLabGitService('tok'))->getRepoOptions();

        $this->assertSame(['acme/widget' => 'acme/widget', 'acme/other' => 'acme/other'], $options);
    }

    public function test_get_branch_options_returns_branch_name_map(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/repository/branches*' => MockResponse::make([
                ['name' => 'main'],
                ['name' => 'develop'],
            ]),
        ]);

        $options = (new GitLabGitService('tok'))->getBranchOptions('acme/widget');

        $this->assertSame(['main' => 'main', 'develop' => 'develop'], $options);
    }

    public function test_get_branch_options_returns_empty_for_invalid_input(): void
    {
        $options = (new GitLabGitService('tok'))->getBranchOptions('');
        $this->assertSame([], $options);

        $options = (new GitLabGitService('tok'))->getBranchOptions('no-slash');
        $this->assertSame([], $options);
    }

    public function test_self_hosted_uses_custom_instance_url(): void
    {
        Saloon::fake([
            'https://git.example.com/api/v4/projects*' => MockResponse::make([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
            ]),
        ]);

        (new GitLabGitService('tok', 'https://git.example.com'))->listRepositories();

        Saloon::assertSent(fn (Request $request, $response): bool => str_starts_with((string) $response->getPendingRequest()->getUrl(), 'https://git.example.com/api/v4/'));
    }

    public function test_no_private_token_header_sent(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects*' => MockResponse::make([]),
        ]);

        (new GitLabGitService('tok'))->listRepositories();

        Saloon::assertSent(fn (Request $request, $response): bool => $response->getPendingRequest()->headers()->get('PRIVATE-TOKEN') === null);
    }

    public function test_get_default_branch_returns_api_value(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget' => MockResponse::make([
                'name' => 'widget',
                'default_branch' => 'develop',
            ]),
        ]);

        $branch = (new GitLabGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertSame('develop', $branch);
    }

    public function test_get_default_branch_returns_null_for_invalid_input(): void
    {
        $service = new GitLabGitService('tok');

        $this->assertNull($service->getDefaultBranch(''));
        $this->assertNull($service->getDefaultBranch('only-name-no-slash'));
    }

    public function test_get_default_branch_returns_null_on_http_failure(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/*' => MockResponse::make(['message' => 'Not Found'], 404),
        ]);

        $branch = (new GitLabGitService('tok'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_field_missing(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget' => MockResponse::make(['name' => 'widget']),
        ]);

        $branch = (new GitLabGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_uses_self_hosted_instance_url(): void
    {
        Saloon::fake([
            'https://gitlab.firma.de/api/v4/projects/acme%2Fwidget' => MockResponse::make([
                'default_branch' => 'main',
            ]),
        ]);

        $branch = (new GitLabGitService('tok', 'https://gitlab.firma.de'))->getDefaultBranch('acme/widget');

        $this->assertSame('main', $branch);
    }

    public function test_comment_on_pull_request_posts_to_merge_request_notes(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests/7/notes' => MockResponse::make([
                'id' => 9001, 'body' => 'hello',
            ]),
        ]);

        $response = (new GitLabGitService('tok'))->commentOnPullRequest('acme', 'widget', 7, 'hello');

        $this->assertSame(9001, $response['id']);
        Saloon::assertSent(function (Request $request): bool {
            $body = $request instanceof CommentOnMergeRequest ? $request->body()->all() : [];

            return $request instanceof CommentOnMergeRequest
                && str_contains($request->resolveEndpoint(), '/merge_requests/7/notes')
                && ($body['body'] ?? null) === 'hello';
        });
    }

    public function test_update_pull_request_puts_title_and_description(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests/7' => MockResponse::make(['iid' => 7]),
        ]);

        (new GitLabGitService('tok'))->updatePullRequest('acme', 'widget', 7, 'new title', 'new body');

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request instanceof UpdateMergeRequest ? $request->body()->all() : [];

            return $request instanceof UpdateMergeRequest
                && $response->getPendingRequest()->getMethod()->value === 'PUT'
                && str_contains($request->resolveEndpoint(), '/merge_requests/7')
                && ($body['title'] ?? null) === 'new title'
                && ($body['description'] ?? null) === 'new body';
        });
    }
}
