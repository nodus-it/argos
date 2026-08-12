<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The caller's natural key for a task, unique per project — two automations may
 * derive their keys from the same namespace. Nullable: UI and MCP have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('external_ref')->nullable()->after('slug');
            $table->unique(['repo_profile_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['repo_profile_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
