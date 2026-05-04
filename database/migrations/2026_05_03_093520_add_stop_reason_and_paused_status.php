<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phase_runs', function (Blueprint $table): void {
            $table->string('stop_reason', 64)->nullable()->after('exit_code');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->enum('workflow_status', [
                    'draft', 'concept_running', 'concept_review',
                    'implement_running', 'implement_paused',
                    'in_review', 'completed', 'failed',
                ])->default('draft')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE phase_runs MODIFY COLUMN status ENUM('running','completed','failed','quality_gate_failed','no_changes','paused')");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_status ENUM('pending','running','completed','failed','quality_gate_failed','no_changes','paused') NULL");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN workflow_status ENUM('draft','concept_running','concept_review','implement_running','implement_paused','in_review','completed','failed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        Schema::table('phase_runs', function (Blueprint $table): void {
            $table->dropColumn('stop_reason');
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE phase_runs MODIFY COLUMN status ENUM('running','completed','failed','quality_gate_failed','no_changes')");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_status ENUM('pending','running','completed','failed','quality_gate_failed','no_changes') NULL");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN workflow_status ENUM('draft','concept_running','concept_review','implement_running','in_review','completed','failed') NOT NULL DEFAULT 'draft'");
    }
};
