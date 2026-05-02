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
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE phase_runs MODIFY COLUMN phase ENUM('concept','implement','diff','push','commit-message','respond') NOT NULL");

            return;
        }

        // SQLite (test environment): recreate the table with the updated CHECK constraint.
        Schema::table('phase_runs', function (Blueprint $table) {
            $table->enum('phase', ['concept', 'implement', 'diff', 'push', 'commit-message', 'respond'])->change();
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE phase_runs MODIFY COLUMN phase ENUM('concept','implement','diff','push','commit-message') NOT NULL");

            return;
        }

        Schema::table('phase_runs', function (Blueprint $table) {
            $table->enum('phase', ['concept', 'implement', 'diff', 'push', 'commit-message'])->change();
        });
    }
};
