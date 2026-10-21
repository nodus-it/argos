<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the task system: workspaces and auto-threads replace it. Task data
 * is deleted, not migrated. Codex is gone too (re-addable later as a second
 * daemon session), so its rows are removed before the enum loses the case.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['agent_credentials', 'agent_versions', 'worker_image_builds'] as $table) {
            DB::table($table)->where('agent_name', 'codex')->delete();
        }
        DB::table('repo_profiles')->where('worker_agent_name', 'codex')->update(['worker_agent_name' => null]);

        DB::table('demos')->whereNull('workspace_id')->delete();
        Schema::table('demos', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->dropIndex(['task_id']);
        });
        Schema::table('demos', function (Blueprint $table): void {
            $table->dropColumn('task_id');
        });

        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
        });
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'task_imported_at', 'concept_comment_id']);
        });

        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'model_concept',
                'model_implement',
                'max_turns_concept',
                'max_turns_implement',
                'auto_concept',
                'auto_pr',
                'worker_config',
            ]);
        });

        Schema::dropIfExists('phase_runs');
        Schema::dropIfExists('tasks');
    }

    /** One-way: the task system and its data are gone. */
    public function down(): void {}
};
