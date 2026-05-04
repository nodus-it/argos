<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite uses TEXT columns — no ALTER needed.
        // MySQL/MariaDB require the ENUM to be explicitly re-declared with the new value.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE repo_profiles MODIFY COLUMN platform ENUM('github', 'gitlab', 'bitbucket') NOT NULL DEFAULT 'github'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE repo_profiles MODIFY COLUMN platform ENUM('github', 'gitlab') NOT NULL DEFAULT 'github'");
        }
    }
};
