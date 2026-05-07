<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('name')->unique();
            $table->ulid('repo_profile_id')->nullable();
            $table->text('description');
            $table->string('base_branch')->nullable();
            $table->string('feature_branch')->nullable();
            $table->string('pr_url')->nullable();
            $table->longText('concept_md')->nullable();
            $table->text('concept_notes')->nullable();
            $table->longText('implement_summary_nontechnical')->nullable();
            $table->longText('implement_summary_technical')->nullable();
            $table->text('implement_notes')->nullable();
            $table->enum('workflow_status', [
                'draft',
                'concept_running',
                'concept_review',
                'implement_running',
                'implement_paused',
                'in_review',
                'completed',
                'failed',
            ])->default('draft');
            $table->boolean('auto_concept')->default(false);
            $table->unsignedSmallInteger('max_turns')->nullable();
            $table->string('worker_image')->nullable();
            $table->enum('current_phase', [
                'concept',
                'implement',
                'diff',
                'push',
                'respond',
            ])->nullable();
            $table->enum('current_status', [
                'pending',
                'running',
                'completed',
                'failed',
                'quality_gate_failed',
                'no_changes',
                'paused',
                'rate_limited',
            ])->nullable();
            $table->timestamps();

            $table->foreign('repo_profile_id')
                ->references('id')
                ->on('repo_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
