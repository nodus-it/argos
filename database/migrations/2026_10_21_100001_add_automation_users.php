<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-threads always act for a person (their agent, their membership):
 * project tokens and issue triggers use the project's automation user, API
 * clients the user they run as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->foreignId('automation_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->foreignId('run_as_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('run_as_user_id');
        });
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automation_user_id');
        });
    }
};
