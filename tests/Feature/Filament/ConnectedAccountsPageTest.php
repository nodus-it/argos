<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\ConnectedAccounts;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectedAccountsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_page_renders_successfully(): void
    {
        Livewire::test(ConnectedAccounts::class)
            ->assertSuccessful()
            ->assertSee('Connected Accounts');
    }

    public function test_shows_not_connected_state_when_no_github_account(): void
    {
        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Not connected');
    }

    public function test_shows_connected_state_when_github_account_exists(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
            'nickname' => 'myuser',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Connected')
            ->assertSee('myuser');
    }

    public function test_shows_connect_button_when_not_connected(): void
    {
        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Connect with GitHub');
    }

    public function test_disconnect_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->call('disconnectGitHub')
            ->assertNotified();

        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);
    }

    public function test_disconnect_shows_success_notification(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->call('disconnectGitHub')
            ->assertNotified('GitHub connection disconnected');
    }

    public function test_page_is_in_settings_navigation_group(): void
    {
        $this->assertSame('Configuration', ConnectedAccounts::getNavigationGroup());
    }

    public function test_only_current_users_account_shown(): void
    {
        $otherUser = User::factory()->create();

        ConnectedAccount::factory()->create([
            'user_id' => $otherUser->id,
            'provider' => 'github',
            'nickname' => 'other-user',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertDontSee('other-user')
            ->assertSee('Not connected');
    }
}
