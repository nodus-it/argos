<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-threads: threads that plan, implement, gate and ship without a human
 * (see App\Services\Workspace\AutoThreadDriver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            // null = an ordinary thread.
            $table->string('auto_state', 32)->nullable()->after('status');
            // Effective settings after project defaults and overrides.
            $table->json('auto_config')->nullable()->after('auto_state');
            // {fix_rounds, turns, started_at}
            $table->json('auto_counters')->nullable()->after('auto_config');
            // Why it stopped (failed, budget_exceeded, paused).
            $table->text('auto_message')->nullable()->after('auto_counters');

            $table->index('auto_state');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            // manual | api | mcp | issue — automatic cleanup only touches non-manual ones.
            $table->string('origin', 16)->default('manual')->after('created_by');
        });

        Schema::table('repo_profiles', function (Blueprint $table): void {
            // Project defaults for auto-threads; null keys fall back to config.
            $table->json('auto_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn('auto_settings');
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropIndex(['auto_state']);
            $table->dropColumn(['auto_state', 'auto_config', 'auto_counters', 'auto_message']);
        });
    }
};
