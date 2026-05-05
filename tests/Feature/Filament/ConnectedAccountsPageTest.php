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
        config(['services.github.client_id' => 'test-client-id']);

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
        config(['services.github.client_id' => 'test-client-id']);

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
        config(['services.github.client_id' => 'test-client-id']);

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

    // GitLab tests

    public function test_gitlab_section_always_visible_without_configuration(): void
    {
        config(['services.gitlab.client_id' => null]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('GitLab')
            ->assertSee('Not configured');
    }

    public function test_gitlab_section_shows_not_configured_description_when_unconfigured(): void
    {
        config(['services.gitlab.client_id' => null]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('GITLAB_CLIENT_ID');
    }

    public function test_gitlab_section_shows_not_connected_when_configured_but_not_linked(): void
    {
        config(['services.gitlab.client_id' => 'test-client-id']);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('GitLab')
            ->assertSee('Connect your GitLab account');
    }

    public function test_gitlab_section_shows_connected_state_when_linked(): void
    {
        config(['services.gitlab.client_id' => 'test-client-id']);

        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
            'nickname' => 'gitlabuser',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Connected')
            ->assertSee('gitlabuser');
    }

    public function test_disconnect_gitlab_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->call('disconnectGitLab')
            ->assertNotified('GitLab connection disconnected');

        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
        ]);
    }

    // Bitbucket tests

    public function test_bitbucket_section_always_visible_without_configuration(): void
    {
        config(['services.bitbucket.client_id' => null]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Bitbucket')
            ->assertSee('Not configured');
    }

    public function test_bitbucket_section_shows_not_configured_description_when_unconfigured(): void
    {
        config(['services.bitbucket.client_id' => null]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('BITBUCKET_CLIENT_ID');
    }

    public function test_bitbucket_section_shows_not_connected_when_configured_but_not_linked(): void
    {
        config(['services.bitbucket.client_id' => 'test-client-id']);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Bitbucket')
            ->assertSee('Connect your Bitbucket account');
    }

    public function test_bitbucket_section_shows_connected_state_when_linked(): void
    {
        config(['services.bitbucket.client_id' => 'test-client-id']);

        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
            'nickname' => 'bitbucketuser',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSee('Connected')
            ->assertSee('bitbucketuser');
    }

    public function test_disconnect_bitbucket_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->call('disconnectBitbucket')
            ->assertNotified('Bitbucket connection disconnected');

        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
        ]);
    }

    public function test_disconnect_bitbucket_only_removes_current_users_account(): void
    {
        $otherUser = User::factory()->create();

        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
        ]);

        ConnectedAccount::factory()->create([
            'user_id' => $otherUser->id,
            'provider' => 'bitbucket',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->call('disconnectBitbucket');

        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
        ]);

        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $otherUser->id,
            'provider' => 'bitbucket',
        ]);
    }
}
