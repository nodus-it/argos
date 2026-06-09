<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Filament\Admin\Pages\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_route_renders_redesigned_page(): void
    {
        // Wiring: the /admin/login route must render our custom split-screen
        // view (the default Filament login has none of these strings).
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('One view.')
            ->assertSee('Email address')
            ->assertSee('Sign in')
            ->assertSee('control-room');
    }

    public function test_login_livewire_component_renders(): void
    {
        Livewire::test(Login::class)
            ->assertSuccessful()
            ->assertSee('Welcome back');
    }

    public function test_remember_defaults_to_on(): void
    {
        Livewire::test(Login::class)
            ->assertSet('data.remember', true);
    }

    public function test_user_can_authenticate(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->set('data.remember', true)
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_show_error(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'wrong-password')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }
}
