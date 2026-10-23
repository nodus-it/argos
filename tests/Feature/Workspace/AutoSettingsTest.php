<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Filament\App\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AutoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class AutoSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_defaults_apply_without_workspace_settings(): void
    {
        $this->assertSame(
            ['plan' => 'auto', 'ship' => 'pr', 'max_fix_rounds' => 3, 'max_turns' => 60, 'max_cost_usd' => 0.0, 'max_minutes' => 120],
            AutoSettings::resolve(null),
        );
    }

    public function test_workspace_settings_override_config_and_runs_override_the_workspace(): void
    {
        $workspace = Workspace::factory()->create(['auto_settings' => ['plan' => 'approve', 'max_turns' => 30]]);

        $settings = AutoSettings::resolve($workspace, ['max_turns' => 10, 'ship' => 'draft_pr']);

        $this->assertSame('approve', $settings['plan']);
        $this->assertSame(10, $settings['max_turns']);
        $this->assertSame('draft_pr', $settings['ship']);
        $this->assertSame(3, $settings['max_fix_rounds']);
    }

    public function test_invalid_or_empty_values_inherit(): void
    {
        $workspace = Workspace::factory()->create(['auto_settings' => ['plan' => 'yolo', 'max_turns' => 0, 'max_minutes' => '', 'evil' => 1]]);

        $settings = AutoSettings::resolve($workspace, ['max_fix_rounds' => 99]);

        $this->assertSame('auto', $settings['plan']);
        $this->assertSame(60, $settings['max_turns']);
        $this->assertSame(120, $settings['max_minutes']);
        $this->assertSame(3, $settings['max_fix_rounds']);
        $this->assertSame([], AutoSettings::clean(['evil' => 1]));
    }

    public function test_the_workspace_form_stores_only_valid_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        // The form validates the default branch against the provider.
        Saloon::fake(['api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']])]);
        $workspace = Workspace::factory()->create(['default_branch' => 'main']);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['auto_settings' => ['plan' => 'approve', 'ship' => 'none', 'max_fix_rounds' => 2, 'max_turns' => null, 'max_cost_usd' => null, 'max_minutes' => 45]], 'automationForm')
            ->call('saveAutomation')
            ->assertHasNoFormErrors([], 'automationForm');

        $this->assertSame(['plan' => 'approve', 'ship' => 'none', 'max_fix_rounds' => 2, 'max_minutes' => 45], $workspace->fresh()->auto_settings);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['auto_settings' => ['plan' => null, 'ship' => null, 'max_fix_rounds' => null, 'max_turns' => null, 'max_cost_usd' => null, 'max_minutes' => null]], 'automationForm')
            ->call('saveAutomation')
            ->assertHasNoFormErrors([], 'automationForm');

        $this->assertNull($workspace->fresh()->auto_settings);
    }
}
