<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phase_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('task_id');
            $table->enum('phase', [
                'concept',
                'implement',
                'diff',
                'push',
                'commit-message',
                'respond',
            ]);
            $table->integer('iteration');
            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed',
                'quality_gate_failed',
                'no_changes',
                'paused',
                'lock_blocked',
                'rate_limited',
            ])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('stop_reason', 64)->nullable();
            $table->json('result_json')->nullable();
            $table->longText('concept_md')->nullable();
            $table->text('concept_notes')->nullable();
            $table->longText('stream_log')->nullable();
            $table->text('error_log')->nullable();
            $table->longText('implement_summary_nontechnical')->nullable();
            $table->longText('implement_summary_technical')->nullable();
            $table->text('implement_notes')->nullable();
            $table->decimal('cost_usd', 8, 6)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->foreign('task_id')
                ->references('id')
                ->on('tasks')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phase_runs');
    }
};
