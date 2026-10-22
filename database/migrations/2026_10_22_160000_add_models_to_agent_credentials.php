<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The models Claude Code last reported for a subscription (was cache-only). */
    public function up(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            $table->json('models')->nullable()->after('default_model');
        });
    }

    public function down(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            $table->dropColumn('models');
        });
    }
};
