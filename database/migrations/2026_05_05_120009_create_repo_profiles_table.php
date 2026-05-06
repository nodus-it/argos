<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_profiles');
    }
};
