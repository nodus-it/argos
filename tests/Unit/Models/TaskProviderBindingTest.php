<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\TaskProviderBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_enum_casts_work(): void
    {
        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitLab,
            'mode' => TaskProviderMode::Webhook,
            'sync_status' => TaskProviderSyncStatus::Active,
        ]);

        $fresh = $binding->fresh();

        $this->assertSame(TaskProviderKind::GitLab, $fresh->kind);
        $this->assertSame(TaskProviderMode::Webhook, $fresh->mode);
        $this->assertSame(TaskProviderSyncStatus::Active, $fresh->sync_status);
    }

    public function test_webhook_secret_is_encrypted(): void
    {
        $binding = TaskProviderBinding::factory()->create([
            'webhook_secret' => 'super-secret',
        ]);

        $rawValue = DB::table('task_provider_bindings')
            ->where('id', $binding->id)
            ->value('webhook_secret');

        $this->assertNotSame('super-secret', $rawValue, 'webhook_secret must be stored encrypted');
        $this->assertSame('super-secret', $binding->fresh()->webhook_secret, 'Model must decrypt value');
    }

    public function test_filters_cast_to_array(): void
    {
        $binding = TaskProviderBinding::factory()->create([
            'filters' => ['state' => 'open', 'labels' => ['argos']],
        ]);

        $this->assertIsArray($binding->fresh()->filters);
        $this->assertSame('open', $binding->fresh()->filters['state']);
    }

    public function test_factory_creates_with_defaults(): void
    {
        $binding = TaskProviderBinding::factory()->create();

        $this->assertSame(TaskProviderKind::GitHub, $binding->kind);
        $this->assertSame(TaskProviderMode::Disabled, $binding->mode);
        $this->assertSame(TaskProviderSyncStatus::Pending, $binding->sync_status);
    }
}
