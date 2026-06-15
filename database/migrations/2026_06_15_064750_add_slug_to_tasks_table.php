<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the dual role of tasks.name (I3). `name` becomes a free, renameable,
 * non-unique display field; a new frozen `slug` carries the operational
 * identity (volume name, TASK_ID/branch, log paths).
 *
 * Continuity: backfill slug = name verbatim. Since name is unique and
 * slug-shaped today, every existing task keeps its exact volume/branch/log
 * key — no orphaned workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        DB::table('tasks')->whereNull('slug')->update(['slug' => DB::raw('name')]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
            $table->dropUnique('tasks_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_slug_unique');
            $table->unique('name');
            $table->dropColumn('slug');
        });
    }
};
