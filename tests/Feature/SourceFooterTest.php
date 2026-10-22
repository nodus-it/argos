<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_exposes_source_url_for_agpl_compliance(): void
    {
        config()->set('argos.source_url', 'https://github.com/example/argos-fork');

        $response = $this->get(route('filament.workspaces.auth.login'));

        $response->assertOk();
        $response->assertSee('https://github.com/example/argos-fork', false);
        $response->assertSee('AGPL-3.0', false);
    }

    public function test_authed_dashboard_exposes_source_url(): void
    {
        config()->set('argos.source_url', 'https://github.com/example/argos-fork');
        Workspace::factory()->create();
        $this->actingAs(User::factory()->create());

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('https://github.com/example/argos-fork', false);
    }
}
