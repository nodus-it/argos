<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support ALTER COLUMN on enum constraints.
        // Run `php artisan migrate:fresh` on SQLite development databases.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_phase ENUM('concept','implement','diff','push','respond') NULL");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_status ENUM('pending','running','completed','failed','quality_gate_failed','no_changes') NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_phase ENUM('concept','implement','diff','push') NULL");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN current_status ENUM('pending','running','completed','failed') NULL");
    }
};
