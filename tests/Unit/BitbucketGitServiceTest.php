<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bitbucket\BitbucketGitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitbucketGitServiceTest extends TestCase
{
    public function test_get_default_branch_returns_api_value(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget' => Http::response([
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
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories/*' => Http::response(['type' => 'error'], 404),
        ]);

        $branch = (new BitbucketGitService('user:app_password'))->getDefaultBranch('acme/missing');

        $this->assertNull($branch);
    }

    public function test_get_default_branch_returns_null_when_mainbranch_missing(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget' => Http::response([
                'full_name' => 'acme/widget',
            ]),
        ]);

        $branch = (new BitbucketGitService('user:app_password'))->getDefaultBranch('acme/widget');

        $this->assertNull($branch);
    }

    public function test_get_repo_options_returns_keyed_array(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories*' => Http::response([
                'values' => [
                    ['full_name' => 'acme/alpha'],
                    ['full_name' => 'acme/beta'],
                ],
            ]),
        ]);

        $options = (new BitbucketGitService('user:pass'))->getRepoOptions();

        $this->assertSame(['acme/alpha' => 'acme/alpha', 'acme/beta' => 'acme/beta'], $options);
    }

    public function test_get_branch_options_returns_keyed_array(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/refs/branches*' => Http::response([
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
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories*' => Http::response(['values' => []]),
        ]);

        (new BitbucketGitService('myuser:mysecret'))->listRepositories();

        Http::assertSent(function ($request): bool {
            $authHeader = $request->header('Authorization')[0] ?? '';

            return str_starts_with($authHeader, 'Basic ');
        });
    }

    public function test_uses_bearer_auth_for_oauth_token(): void
    {
        Http::fake([
            'https://api.bitbucket.org/2.0/repositories*' => Http::response(['values' => []]),
        ]);

        (new BitbucketGitService('oauthtokenwithoutcolon'))->listRepositories();

        Http::assertSent(function ($request): bool {
            $authHeader = $request->header('Authorization')[0] ?? '';

            return str_starts_with($authHeader, 'Bearer ');
        });
    }
}
