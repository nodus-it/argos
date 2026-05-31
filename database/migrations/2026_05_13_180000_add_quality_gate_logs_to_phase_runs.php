<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phase_runs', function (Blueprint $table): void {
            $table->json('quality_gate_logs')->nullable()->after('error_log');
        });
    }

    public function down(): void
    {
        Schema::table('phase_runs', function (Blueprint $table): void {
            $table->dropColumn('quality_gate_logs');
        });
    }
};
