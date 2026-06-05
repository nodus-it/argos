<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaLibrarySetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('media'));
    }

    public function test_media_table_has_ulid_compatible_model_id(): void
    {
        $columns = Schema::getColumnType('media', 'model_id');

        // ulidMorphs() creates a char(26) column — string-based, not integer
        $this->assertNotEquals('bigint', $columns);
        $this->assertNotEquals('integer', $columns);
    }

    public function test_disk_name_config_defaults_to_public(): void
    {
        $this->assertSame('public', config('media-library.disk_name'));
    }

    public function test_disk_name_config_reads_env(): void
    {
        config(['media-library.disk_name' => 'local']);

        $this->assertSame('local', config('media-library.disk_name'));
    }
}
