<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Services\Integrations\ProviderSetupGuide;
use Tests\TestCase;

class ProviderSetupGuideTest extends TestCase
{
    private ProviderSetupGuide $guide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guide = new ProviderSetupGuide;
    }

    public function test_github_pat_link_prefills_scope_and_description(): void
    {
        $pat = $this->guide->pat(IntegrationProvider::GitHub);

        $this->assertSame('repo', $pat['scopes']);
        $this->assertNotNull($pat['url']);
        $this->assertStringContainsString('github.com/settings/tokens/new', $pat['url']);
        $this->assertStringContainsString('scopes=repo', $pat['url']);
        $this->assertStringContainsString('description=Argos', $pat['url']);
    }

    public function test_gitlab_pat_link_honours_self_hosted_instance(): void
    {
        $pat = $this->guide->pat(IntegrationProvider::GitLab, 'https://gitlab.acme.test/');

        $this->assertSame('api, write_repository', $pat['scopes']);
        $this->assertStringStartsWith('https://gitlab.acme.test/-/user_settings/personal_access_tokens', (string) $pat['url']);
        $this->assertStringContainsString('scopes=api,write_repository', (string) $pat['url']);
    }

    public function test_github_oauth_app_link_prefills_name_and_callback(): void
    {
        $oauth = $this->guide->oauthApp(
            IntegrationProvider::GitHub,
            null,
            'https://argos.test',
            'https://argos.test/auth/github/callback',
        );

        $this->assertNotNull($oauth['url']);
        $this->assertStringContainsString('github.com/settings/applications/new', $oauth['url']);
        $this->assertStringContainsString(rawurlencode('https://argos.test/auth/github/callback'), $oauth['url']);
        $this->assertStringContainsString('Argos', $oauth['url']);
    }

    public function test_bitbucket_oauth_app_has_no_generic_deep_link(): void
    {
        $oauth = $this->guide->oauthApp(IntegrationProvider::Bitbucket, null, 'https://argos.test', 'https://argos.test/auth/bitbucket/callback');

        $this->assertNull($oauth['url']);
        $this->assertNotSame('', $oauth['scopes']);
    }
}
