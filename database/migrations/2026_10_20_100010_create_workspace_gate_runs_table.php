<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_gate_runs', function (Blueprint $table): void {
            // Doubles as the daemon's run id (log file suffix).
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('running');
            // gate => pass|fail|skip
            $table->json('gates')->nullable();
            // gate => last 8 KB of its log
            $table->json('logs')->nullable();
            // Baseline state when the run started: ready → only new failures count.
            $table->string('baseline', 16)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            // missing | running | ready | failed — reported by the daemon.
            $table->string('gate_baseline', 16)->nullable()->after('external_issue');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('gate_baseline');
        });
        Schema::dropIfExists('workspace_gate_runs');
    }
};
