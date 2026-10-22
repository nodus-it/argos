<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When Claude Code last reported the models of a subscription. */
    public function up(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            $table->timestamp('models_refreshed_at')->nullable()->after('models');
        });
    }

    public function down(): void
    {
        Schema::table('agent_credentials', function (Blueprint $table): void {
            $table->dropColumn('models_refreshed_at');
        });
    }
};
