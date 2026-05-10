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
            ."'in_review','completed','failed'"
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

        // Down: rewrite any rows on the new value before shrinking the enum,
        // otherwise MariaDB silently truncates them to ''. We pick
        // implement_running because that's what the old code wrote in this
        // exact spot.
        DB::table('tasks')
            ->where('workflow_status', 'implement_completed')
            ->update(['workflow_status' => 'implement_running']);

        DB::statement(
            'ALTER TABLE tasks MODIFY COLUMN workflow_status ENUM('
            ."'draft','concept_running','concept_review',"
            ."'implement_running','implement_paused',"
            ."'in_review','completed','failed'"
            .") NOT NULL DEFAULT 'draft'"
        );
    }
};
