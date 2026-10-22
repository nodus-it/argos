<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-checking one gate: the run names it (`only`), takes the other results
 * over from the run before and remembers in which run each gate's log lies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_gate_runs', function (Blueprint $table): void {
            $table->string('only', 32)->nullable()->after('baseline');
            $table->json('log_runs')->nullable()->after('logs');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_gate_runs', function (Blueprint $table): void {
            $table->dropColumn(['only', 'log_runs']);
        });
    }
};
