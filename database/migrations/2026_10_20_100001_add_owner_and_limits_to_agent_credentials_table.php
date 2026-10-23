<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            // null = shared agent (pre-existing credentials, used by tasks).
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('max_concurrent_sessions')->default(1)->after('status');
            $table->string('default_model')->nullable()->after('max_concurrent_sessions');
            $table->timestamp('usage_limited_until')->nullable()->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['max_concurrent_sessions', 'default_model', 'usage_limited_until']);
        });
    }
};
