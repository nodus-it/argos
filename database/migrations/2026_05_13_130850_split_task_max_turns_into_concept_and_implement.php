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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_turns_concept')->nullable()->after('max_turns');
            $table->unsignedSmallInteger('max_turns_implement')->nullable()->after('max_turns_concept');
        });

        // Existing tasks with a max_turns value were using it as the implement
        // limit (the UI labelled it as such), so preserve the intent.
        DB::table('tasks')
            ->whereNotNull('max_turns')
            ->update(['max_turns_implement' => DB::raw('max_turns')]);

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('max_turns');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_turns')->nullable();
        });

        // Restore from the implement side — that mirrors what the old column meant.
        DB::table('tasks')
            ->whereNotNull('max_turns_implement')
            ->update(['max_turns' => DB::raw('max_turns_implement')]);

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['max_turns_concept', 'max_turns_implement']);
        });
    }
};
