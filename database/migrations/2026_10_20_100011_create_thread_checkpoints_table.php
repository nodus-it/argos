<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_checkpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            // Number within the thread; the git ref is refs/argos/checkpoints/<thread>/<n>.
            $table->unsignedInteger('n');
            $table->string('ref');
            $table->string('sha', 64);
            $table->string('head_sha', 64)->nullable();
            $table->string('label', 120)->nullable();
            // The user message this checkpoint precedes (null: manual or pre-restore).
            $table->foreignId('thread_event_id')->nullable()->constrained('thread_events')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['thread_id', 'n']);
            $table->index('thread_event_id');
        });

        Schema::table('threads', function (Blueprint $table): void {
            // Told to Claude with the next message after a restore.
            $table->text('restore_note')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropColumn('restore_note');
        });
        Schema::dropIfExists('thread_checkpoints');
    }
};
