<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache for agent version-tracking. Agents themselves are code (not DB),
 * so their installed/upstream version + update flag has nowhere to go
 * inside worker_agents (which doesn't exist). One row per AgentName.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_versions', function (Blueprint $table): void {
            $table->string('agent_name', 64)->primary();
            $table->string('installed_version')->nullable();
            $table->string('upstream_version')->nullable();
            $table->boolean('has_update')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_versions');
    }
};
