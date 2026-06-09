<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_oauth_configs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->enum('provider', ['github', 'gitlab', 'bitbucket', 'linear']);
            // '' is the sentinel for the provider's public SaaS instance. A
            // non-empty value is a self-hosted instance (GitLab CE/EE, Bitbucket
            // Server). Kept NOT NULL so the unique index below stays reliable on
            // MariaDB (which treats NULLs as distinct).
            $table->string('instance_url')->default('');
            $table->string('client_id');
            // Encrypted via the model cast.
            $table->text('client_secret');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'instance_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_oauth_configs');
    }
};
