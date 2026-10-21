<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session queue: a task whose message found no free session (workspace or
 * subscription limit) waits here, oldest first; its messages are stored as
 * pending and delivered once a session frees up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('session_open');
            $table->index(['workspace_id', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'queued_at']);
            $table->dropColumn('queued_at');
        });
    }
};
