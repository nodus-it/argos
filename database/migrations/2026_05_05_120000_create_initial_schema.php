<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated initial schema. Replaces the 27 incremental migrations from the
 * pre-1.0 development cycle. Pre-release self-host installs were never
 * supported — there is no upgrade path from the old schema; new installs
 * apply this single file from a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Laravel defaults ────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('locale', 5)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // ── Argos: connected accounts (OAuth-linked Git host accounts) ──────
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->text('token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('avatar')->nullable();
            $table->string('instance_url')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

        // ── Argos: repo profiles (per-repository credentials + settings) ────
        Schema::create('repo_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('url');
            $table->text('token')->nullable();
            $table->string('default_branch')->default('main');
            $table->enum('platform', ['github', 'gitlab', 'bitbucket'])->default('github');
            $table->string('auth_method', 10)->default('pat');
            $table->foreignId('connected_account_id')
                ->nullable()
                ->constrained('connected_accounts')
                ->nullOnDelete();
            $table->string('worker_image')->nullable();
            $table->boolean('auto_concept')->default(false);
            $table->boolean('auto_pr')->default(false);
            $table->timestamps();
        });

        // ── Argos: tasks (the unit of work users create) ────────────────────
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
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

        // ── Argos: phase runs (one row per phase invocation per task) ───────
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
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('repo_profiles');
        Schema::dropIfExists('connected_accounts');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
