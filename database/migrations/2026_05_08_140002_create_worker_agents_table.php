<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_agents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('label');
            $table->boolean('is_builtin')->default(false);
            $table->text('install_script');
            $table->string('runner_class');
            $table->string('npm_pkg')->nullable();
            $table->string('pinned_version')->nullable();
            $table->json('requires_stack_capabilities')->nullable();
            $table->json('config_schema')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('installed_version')->nullable();
            $table->string('upstream_version')->nullable();
            $table->boolean('has_update')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_built_at')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_agents');
    }
};
