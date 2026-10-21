<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review mode: which file a person marked as seen (per thread, with the
 * state of the file's changes it was seen at — a later change unmarks it)
 * and per-user UI choices such as split vs. unified diff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_file_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1000);
            $table->char('path_hash', 40);
            $table->char('file_hash', 40);
            $table->timestamps();
            $table->unique(['thread_id', 'user_id', 'path_hash']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('ui_prefs')->nullable()->after('notification_prefs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_file_views');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_prefs');
        });
    }
};
