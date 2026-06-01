<?php

declare(strict_types=1);

namespace Tests\Feature\OAuth;

use App\Enums\IntegrationProvider;
use App\Models\ProviderOAuthConfig;
use App\Services\OAuth\OAuthConfigHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthConfigHydratorTest extends TestCase
{
    use RefreshDatabase;

    private OAuthConfigHydrator $hydrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hydrator = app(OAuthConfigHydrator::class);
        $this->hydrator->forgetCache();
    }

    public function test_hydrate_pushes_db_config_into_services(): void
    {
        config(['services.github.client_id' => 'env-cid', 'services.github.client_secret' => 'env-sec']);

        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'client_id' => 'db-cid',
            'client_secret' => 'db-sec',
        ]);
        $this->hydrator->forgetCache();

        $this->hydrator->hydrate();

        $this->assertSame('db-cid', config('services.github.client_id'));
        $this->assertSame('db-sec', config('services.github.client_secret'));
    }

    public function test_hydrate_leaves_env_in_place_when_no_db_row(): void
    {
        config(['services.github.client_id' => 'env-cid', 'services.github.client_secret' => 'env-sec']);

        $this->hydrator->hydrate();

        $this->assertSame('env-cid', config('services.github.client_id'));
        $this->assertSame('env-sec', config('services.github.client_secret'));
    }

    public function test_hydrate_skips_disabled_config(): void
    {
        config(['services.github.client_id' => 'env-cid']);

        ProviderOAuthConfig::factory()->disabled()->create([
            'provider' => IntegrationProvider::GitHub,
            'client_id' => 'db-cid',
        ]);
        $this->hydrator->forgetCache();

        $this->hydrator->hydrate();

        $this->assertSame('env-cid', config('services.github.client_id'));
    }

    public function test_hydrate_sets_gitlab_instance_uri_with_trailing_slash(): void
    {
        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitLab,
            'instance_url' => '',
            'client_id' => 'gl-cid',
            'client_secret' => 'gl-sec',
        ]);
        $this->hydrator->forgetCache();

        $this->hydrator->hydrate();

        $this->assertSame('https://gitlab.com/', config('services.gitlab.instance_uri'));
    }

    public function test_resolve_prefers_db_then_falls_back_to_env(): void
    {
        config(['services.bitbucket.client_id' => 'env-bb', 'services.bitbucket.client_secret' => 'env-bbs']);

        // No row → ENV fallback.
        $resolved = $this->hydrator->resolve('bitbucket');
        $this->assertSame('env-bb', $resolved['client_id']);

        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::Bitbucket,
            'client_id' => 'db-bb',
            'client_secret' => 'db-bbs',
        ]);

        $resolved = $this->hydrator->resolve('bitbucket');
        $this->assertSame('db-bb', $resolved['client_id']);
        $this->assertSame('db-bbs', $resolved['client_secret']);
    }

    public function test_saving_a_config_invalidates_the_cache(): void
    {
        config(['services.github.client_id' => 'env-cid']);

        // Prime the cache with no rows.
        $this->hydrator->hydrate();
        $this->assertSame('env-cid', config('services.github.client_id'));

        // The model's saved() hook must clear the cache so the next hydrate sees it.
        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'client_id' => 'fresh-cid',
            'client_secret' => 'fresh-sec',
        ]);

        $this->hydrator->hydrate();
        $this->assertSame('fresh-cid', config('services.github.client_id'));
    }
}
