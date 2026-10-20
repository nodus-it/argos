<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_workspaces(): void
    {
        $this->get('/')->assertRedirect('/w');
    }

    public function test_root_redirects_to_admin_when_workspaces_are_off(): void
    {
        config(['argos.workspaces.enabled' => false]);

        $this->get('/')->assertRedirect('/admin');
    }

    public function test_old_workspace_links_keep_working(): void
    {
        $this->get('/admin/workspaces/01abc?thread=t1')->assertRedirect('/w/workspaces/01abc?thread=t1');
        $this->get('/admin/workspaces')->assertRedirect('/w/workspaces');
    }
}
