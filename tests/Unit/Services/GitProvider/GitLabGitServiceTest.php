<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Services\GitProvider\GitLabGitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitLabGitServiceTest extends TestCase
{
    public function test_list_repositories_uses_bearer_auth(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects*' => Http::response([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
            ]),
        ]);

        (new GitLabGitService('glpat-test'))->listRepositories();

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer glpat-test');
        });
    }

    public function test_list_repositories_returns_array(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects*' => Http::response([
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
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/repository/branches*' => Http::response([
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
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests' => Http::response([
                'id' => 1,
                'web_url' => 'https://gitlab.com/acme/widget/-/merge_requests/1',
            ], 201),
        ]);

        $result = (new GitLabGitService('tok'))->createPullRequest(
            'acme', 'widget', 'My MR', 'Description', 'feature', 'main'
        );

        $this->assertSame('https://gitlab.com/acme/widget/-/merge_requests/1', $result['web_url']);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $body['source_branch'] === 'feature' && $body['target_branch'] === 'main';
        });
    }

    public function test_get_repo_options_returns_path_with_namespace_map(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects*' => Http::response([
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
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/repository/branches*' => Http::response([
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
        Http::fake([
            'https://git.example.com/api/v4/projects*' => Http::response([
                ['id' => 1, 'path_with_namespace' => 'acme/widget'],
            ]),
        ]);

        (new GitLabGitService('tok', 'https://git.example.com'))->listRepositories();

        Http::assertSent(function ($request): bool {
            return str_starts_with((string) $request->url(), 'https://git.example.com/api/v4/');
        });
    }

    public function test_no_private_token_header_sent(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects*' => Http::response([]),
        ]);

        (new GitLabGitService('tok'))->listRepositories();

        Http::assertSent(function ($request): bool {
            return ! $request->hasHeader('PRIVATE-TOKEN');
        });
    }

    public function test_get_default_branch_returns_api_value(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget' => Http::response([
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
        Http::fake([
            'https://gitlab.com/api/v4/projects/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $branch = (new GitLabGitService('tok'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_field_missing(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget' => Http::response(['name' => 'widget']),
        ]);

        $branch = (new GitLabGitService('tok'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_uses_self_hosted_instance_url(): void
    {
        Http::fake([
            'https://gitlab.firma.de/api/v4/projects/acme%2Fwidget' => Http::response([
                'default_branch' => 'main',
            ]),
        ]);

        $branch = (new GitLabGitService('tok', 'https://gitlab.firma.de'))->getDefaultBranch('acme/widget');

        $this->assertSame('main', $branch);
    }

    public function test_comment_on_pull_request_posts_to_merge_request_notes(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests/7/notes' => Http::response([
                'id' => 9001, 'body' => 'hello',
            ]),
        ]);

        $response = (new GitLabGitService('tok'))->commentOnPullRequest('acme', 'widget', 7, 'hello');

        $this->assertSame(9001, $response['id']);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/merge_requests/7/notes')
                && ($request->data()['body'] ?? null) === 'hello';
        });
    }

    public function test_update_pull_request_puts_title_and_description(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/merge_requests/7' => Http::response(['iid' => 7]),
        ]);

        (new GitLabGitService('tok'))->updatePullRequest('acme', 'widget', 7, 'new title', 'new body');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/merge_requests/7')
                && ($request->data()['title'] ?? null) === 'new title'
                && ($request->data()['description'] ?? null) === 'new body';
        });
    }
}
