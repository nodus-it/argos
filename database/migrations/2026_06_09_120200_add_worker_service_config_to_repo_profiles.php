<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            // Per-service credential overrides for the backing services, e.g.
            // {"mysql": {"database": "...", "username": "...", "password": "..."}}.
            // Host/port stay fixed; only credentials are configurable. Used by
            // both the worker sidecars and the live demo.
            $table->json('worker_service_config')->nullable()->after('worker_services');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn('worker_service_config');
        });
    }
};
