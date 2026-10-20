<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            // Slash commands the SDK reported for this session (name,
            // description, argument_hint) — feeds the composer autocomplete.
            $table->json('commands')->nullable()->after('claude_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropColumn('commands');
        });
    }
};
