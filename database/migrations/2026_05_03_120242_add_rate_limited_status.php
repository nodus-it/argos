<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY current_status
            ENUM('pending','running','completed','failed','quality_gate_failed','no_changes','paused','rate_limited')
            NULL");

        DB::statement("ALTER TABLE phase_runs MODIFY status
            ENUM('pending','running','completed','failed','quality_gate_failed','no_changes','paused','rate_limited')
            NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY current_status
            ENUM('pending','running','completed','failed','quality_gate_failed','no_changes','paused')
            NULL");

        DB::statement("ALTER TABLE phase_runs MODIFY status
            ENUM('pending','running','completed','failed','quality_gate_failed','no_changes','paused')
            NOT NULL DEFAULT 'pending'");
    }
};
