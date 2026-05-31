<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            // Per-project defaults for the phase turn budgets. Resolution order:
            // task override → repo-profile default → global config default.
            $table->unsignedSmallInteger('max_turns_concept')->nullable()->after('model_implement');
            $table->unsignedSmallInteger('max_turns_implement')->nullable()->after('max_turns_concept');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn(['max_turns_concept', 'max_turns_implement']);
        });
    }
};
