<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The earlier add_bitbucket_to_platform_enum migration only ran when the
 * driver name was 'mysql' — but Laravel 11 introduced a separate 'mariadb'
 * connection driver, so MariaDB-backed installs silently skipped the
 * ENUM widening and stayed at ('github', 'gitlab'). Re-applying the same
 * MODIFY COLUMN unconditionally on the mariadb driver is idempotent: if
 * the column already lists all three values it's a no-op, otherwise it
 * adds 'bitbucket'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE repo_profiles MODIFY COLUMN platform ENUM('github', 'gitlab', 'bitbucket') NOT NULL DEFAULT 'github'");
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling this back would clip an existing
        // 'bitbucket' value out of the schema while data may still
        // reference it. Revert by rerunning the original migration's down().
    }
};
