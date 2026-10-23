<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status', 16)->default('closed');
            $table->string('permission_mode', 24)->default('default');
            $table->string('model');
            $table->string('claude_session_id')->nullable();
            // Highest daemon event seq the manager has processed.
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            // An open daemon session holds one of the agent's slots.
            $table->boolean('session_open')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['agent_credential_id', 'session_open']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('thread_events', function (Blueprint $table): void {
            // Autoincrement id is the display order.
            $table->id();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Set for events reported by the daemon; second line of defence
            // against re-delivered batches.
            $table->unsignedBigInteger('daemon_seq')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['thread_id', 'daemon_seq']);
            $table->index(['thread_id', 'id']);
        });

        Schema::create('thread_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            $table->string('tool_use_id')->nullable();
            $table->string('kind', 16);
            $table->string('tool_name');
            $table->json('request');
            $table->string('status', 16)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_approvals');
        Schema::dropIfExists('thread_events');
        Schema::dropIfExists('threads');
    }
};
