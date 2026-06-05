<?php

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
        Schema::table('phase_runs', function (Blueprint $table) {
            // The resolved model id (e.g. claude-sonnet-4-6) used for this phase
            // run. Persisted manager-side from PhaseRunner::resolveModel so cost
            // analysis can attribute spend per model without parsing stream_log.
            // Nullable: historical rows and Codex runs (model picked agent-side)
            // may have none.
            $table->string('model', 64)->nullable()->after('output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phase_runs', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
