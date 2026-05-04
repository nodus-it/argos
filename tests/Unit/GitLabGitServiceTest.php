<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GitLab\GitLabGitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitLabGitServiceTest extends TestCase
{
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
}
