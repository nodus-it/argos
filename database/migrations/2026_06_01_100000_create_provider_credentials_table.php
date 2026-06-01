<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('label');
            $table->enum('provider', ['github', 'gitlab', 'bitbucket', 'linear']);
            // Self-hosted instances (GitLab CE/EE, Bitbucket Server). Null = the
            // provider's public SaaS instance.
            $table->string('instance_url')->nullable();
            // Encrypted via the model cast.
            $table->text('token');
            // Free-text reminder of which scopes the token was minted with.
            $table->string('scopes_hint')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'label']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
