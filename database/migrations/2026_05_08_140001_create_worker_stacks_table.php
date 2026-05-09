<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_stacks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('label');
            $table->boolean('is_builtin')->default(false);
            $table->string('base_image');
            $table->text('dockerfile_body');
            $table->json('common_tools')->nullable();
            $table->json('capabilities')->nullable();
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
        Schema::dropIfExists('worker_stacks');
    }
};
