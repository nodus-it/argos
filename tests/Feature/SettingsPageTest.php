<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CredentialStore;
use App\Filament\Admin\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->tmpDir = sys_get_temp_dir().'/argos_settings_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        config(['argos.config_dir' => $this->tmpDir]);
        config(['argos.claude_token' => null]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(Settings::class)
            ->assertSuccessful()
            ->assertSee('Settings');
    }

    public function test_settings_shows_token_missing_state(): void
    {
        config(['argos.claude_token' => null]);

        Livewire::test(Settings::class)
            ->assertSee('not set')
            ->assertSee('claude setup-token');
    }

    public function test_settings_shows_token_set_state(): void
    {
        config(['argos.claude_token' => 'sk-ant-test-token']);

        Livewire::test(Settings::class)
            ->assertSee('set');
    }

    public function test_settings_shows_db_connection(): void
    {
        Livewire::test(Settings::class)
            ->assertSee(config('database.default'));
    }

    public function test_settings_shows_worker_image(): void
    {
        config(['argos.worker_image' => 'argos-worker:local']);

        Livewire::test(Settings::class)
            ->assertSee('argos-worker:local');
    }

    public function test_save_persists_token_to_credential_store(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models' => Http::response([], 200)]);

        Livewire::test(Settings::class)
            ->fillForm(['claude_token' => 'sk-ant-oat01-fresh'])
            ->call('save')
            ->assertNotified();

        $this->assertSame('sk-ant-oat01-fresh', app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_with_empty_token_warns_and_does_not_persist(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['claude_token' => ''])
            ->call('save')
            ->assertNotified();

        $this->assertNull(app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_does_not_overwrite_when_env_token_is_present(): void
    {
        config(['argos.claude_token' => 'env-managed']);

        Livewire::test(Settings::class)
            ->fillForm(['claude_token' => 'attempted-override'])
            ->call('save')
            ->assertNotified();

        $this->assertNull(app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_with_invalid_token_shows_error_and_does_not_persist(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models' => Http::response([], 401)]);

        Livewire::test(Settings::class)
            ->fillForm(['claude_token' => 'sk-ant-oat01-invalid'])
            ->call('save')
            ->assertNotified('Token invalid');

        $this->assertNull(app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_with_network_error_saves_token_with_warning(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models' => function () {
            throw new ConnectionException;
        }]);

        Livewire::test(Settings::class)
            ->fillForm(['claude_token' => 'sk-ant-oat01-unreachable'])
            ->call('save')
            ->assertNotified('Token saved');

        $this->assertSame('sk-ant-oat01-unreachable', app(CredentialStore::class)->getClaudeToken());
    }

    public function test_clear_token_removes_file_token(): void
    {
        app(CredentialStore::class)->setClaudeToken('tok-to-be-removed');

        Livewire::test(Settings::class)
            ->call('clearToken')
            ->assertNotified();

        $this->assertNull(app(CredentialStore::class)->getClaudeToken());
    }

    public function test_token_input_disabled_when_env_source(): void
    {
        config(['argos.claude_token' => 'env-tok']);

        Livewire::test(Settings::class)
            ->assertSee('CLAUDE_CODE_OAUTH_TOKEN');
    }
}
