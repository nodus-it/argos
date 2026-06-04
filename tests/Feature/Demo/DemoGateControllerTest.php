<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoGateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('argos.preview.base_domain', 'preview.argos.test');
        config()->set('app.url', 'https://argos.example.test');
    }

    public function test_authenticated_request_passes_with_204(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/_argos/demo-gate')->assertNoContent();
    }

    public function test_guest_is_redirected_to_login_and_demo_url_is_stored_as_intended(): void
    {
        $response = $this->get('/_argos/demo-gate', [
            'X-Forwarded-Host' => 'demo-x.preview.argos.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Uri' => '/dashboard',
        ]);

        // Login URL must be pinned to APP_URL, not the forwarded demo host.
        $response->assertRedirect('https://argos.example.test/admin/login');
        $this->assertSame('https://demo-x.preview.argos.test/dashboard', session('url.intended'));
    }

    public function test_foreign_forwarded_host_is_never_stored_as_intended(): void
    {
        $response = $this->get('/_argos/demo-gate', [
            'X-Forwarded-Host' => 'evil.example.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Uri' => '/steal',
        ]);

        $response->assertRedirect('https://argos.example.test/admin/login');
        $this->assertNull(session('url.intended'));
    }
}
