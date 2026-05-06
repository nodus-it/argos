<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_authed_dashboard_renders_feedback_button_linking_to_source_issues(): void
    {
        config()->set('argos.source_url', 'https://github.com/example/argos-fork');
        RepoProfile::factory()->create();
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('https://github.com/example/argos-fork/issues/new/choose', false);
        $response->assertSee('fi-argos-feedback-btn', false);
    }

    public function test_feedback_button_is_omitted_when_source_url_is_blank(): void
    {
        config()->set('argos.source_url', '');
        RepoProfile::factory()->create();
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertDontSee('fi-argos-feedback-btn', false);
    }
}
