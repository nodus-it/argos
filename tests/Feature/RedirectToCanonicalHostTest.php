<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectToCanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://argos.localhost:8080']);
        $this->app['env'] = 'local';
    }

    public function test_page_loads_on_another_host_go_to_the_app_host(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])->get('http://localhost:8080/login?x=1')
            ->assertRedirect('http://argos.localhost:8080/login?x=1');
    }

    public function test_the_app_host_is_served_normally(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])->get('http://argos.localhost:8080/login')->assertOk();
    }

    public function test_machine_traffic_is_never_redirected(): void
    {
        $this->get('http://nginx/up')->assertOk();
        // The gate answers for itself (404: unknown host) — never a redirect.
        $this->withHeaders(['Accept' => 'text/html'])->get('http://nginx/_argos/workspace-gate')->assertNotFound();
        $this->getJson('http://nginx/api/v1/workspaces')->assertUnauthorized();
        $this->withHeaders(['Accept' => 'text/html'])->post('http://nginx/webhooks/issues/github/x')->assertNotFound();
    }

    public function test_other_environments_are_left_alone(): void
    {
        $this->app['env'] = 'production';

        $this->withHeaders(['Accept' => 'text/html'])->get('http://localhost:8080/up')->assertOk();
        $this->withHeaders(['Accept' => 'text/html'])->get('http://localhost:8080/login')->assertOk();
    }
}
