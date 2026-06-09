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
            // Project-level secrets, injected into BOTH the worker and the live
            // demo. Encrypted at rest via the model's 'encrypted:array' casts —
            // stored as text, the cast handles JSON + encryption:
            //   composer_registries → built into a COMPOSER_AUTH blob
            //   worker_env          → raw NAME/value env vars
            $table->text('composer_registries')->nullable()->after('worker_config');
            $table->text('worker_env')->nullable()->after('composer_registries');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn(['composer_registries', 'worker_env']);
        });
    }
};
