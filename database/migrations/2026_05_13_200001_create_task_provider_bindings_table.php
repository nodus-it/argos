<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_provider_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('repo_profile_id');
            $table->foreign('repo_profile_id')->references('id')->on('repo_profiles')->cascadeOnDelete();
            $table->string('kind');
            $table->string('mode')->default('disabled');
            $table->unsignedBigInteger('connected_account_id')->nullable();
            $table->foreign('connected_account_id')->references('id')->on('connected_accounts')->nullOnDelete();
            $table->string('external_project_ref')->nullable();
            $table->json('filters')->nullable();
            $table->string('webhook_id')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_provider_bindings');
    }
};
