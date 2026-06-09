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
            // List of App\Enums\BackingService values (e.g. ["mysql","redis"])
            // Argos boots as ephemeral sidecars for this project's worker runs.
            $table->json('worker_services')->nullable()->after('worker_env');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn('worker_services');
        });
    }
};
