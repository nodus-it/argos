<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line comments collected in the review of a thread (per person) until
 * they go to Claude as one message. The anchor (hash of the commented
 * lines) lets a comment follow its lines or turn stale when Claude
 * changes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1000);
            $table->string('side', 8)->default('new');
            $table->unsignedInteger('line_start');
            $table->unsignedInteger('line_end');
            $table->char('anchor_hash', 40);
            $table->text('snippet');
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};
