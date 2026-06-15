<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema::table->enum() in Laravel can't extend an existing ENUM in
        // place — the only portable way is raw ALTER TABLE. SQLite has no
        // ENUM type at all (it accepts any value), so the alter only runs
        // on the relational drivers that actually enforce it.
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE tasks MODIFY COLUMN workflow_status ENUM('
            ."'draft','concept_running','concept_review',"
            ."'implement_running','implement_paused','implement_completed',"
            ."'in_review','completed','failed','aborted'"
            .") NOT NULL DEFAULT 'draft'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // Down: rewrite any aborted rows before shrinking the enum, otherwise
        // MariaDB silently truncates them to ''. Failed is the closest terminal
        // state for a manually aborted task.
        DB::table('tasks')
            ->where('workflow_status', 'aborted')
            ->update(['workflow_status' => 'failed']);

        DB::statement(
            'ALTER TABLE tasks MODIFY COLUMN workflow_status ENUM('
            ."'draft','concept_running','concept_review',"
            ."'implement_running','implement_paused','implement_completed',"
            ."'in_review','completed','failed'"
            .") NOT NULL DEFAULT 'draft'"
        );
    }
};
