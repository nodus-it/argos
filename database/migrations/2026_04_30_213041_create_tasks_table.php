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
            $table->string('name')->unique();
            $table->ulid('repo_profile_id')->nullable();
            $table->text('description');
            $table->string('feature_branch')->nullable();
            $table->string('pr_url')->nullable();
            $table->enum('current_phase', ['concept', 'implement', 'diff', 'push', 'respond'])->nullable();
            $table->enum('current_status', ['pending', 'running', 'completed', 'failed', 'quality_gate_failed', 'no_changes'])->nullable();
            $table->timestamps();

            $table->foreign('repo_profile_id')->references('id')->on('repo_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
