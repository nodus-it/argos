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
        Schema::table('external_issue_links', function (Blueprint $table): void {
            // Set once when a task is first imported for this issue. Survives
            // task deletion (task_id is nulled on delete), so a deleted task is
            // never silently re-imported on the next poll — while an issue that
            // only gains a matching label later still gets imported.
            $table->timestamp('task_imported_at')->nullable()->after('task_id');
        });

        // Backfill links that already have a task, so the new gate does not
        // treat them as "never imported" and create a duplicate on next poll.
        DB::table('external_issue_links')
            ->whereNotNull('task_id')
            ->update(['task_imported_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropColumn('task_imported_at');
        });
    }
};
