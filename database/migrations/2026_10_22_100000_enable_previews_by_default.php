<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previews start on demand per task and are capped by the workspace's
 * preview limit, so new workspaces have them on unless an admin switches
 * them off. Existing workspaces keep their setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('live_demo_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('live_demo_enabled')->default(false)->change();
        });
    }
};
