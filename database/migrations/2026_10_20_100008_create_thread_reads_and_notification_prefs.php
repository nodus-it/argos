<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far each member has read a thread (unread counters).
        Schema::create('thread_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_event_id')->default(0);
            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // Per-event opt-outs for workspace notifications (null = all on).
            $table->json('notification_prefs')->nullable()->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_reads');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_prefs');
        });
    }
};
