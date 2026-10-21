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
}
