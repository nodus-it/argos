<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * worker_stacks.installed_version and .upstream_version were carried in
 * from the early worker-image-management concept doc but never wired to
 * a writer. BuiltinSync tracks drift via `last_builtin_hash`; agent
 * version updates live on the separate `agent_versions` table. The
 * stack columns just rendered as empty fields on the View page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_stacks', function (Blueprint $table): void {
            $table->dropColumn(['installed_version', 'upstream_version']);
        });
    }

    public function down(): void
    {
        Schema::table('worker_stacks', function (Blueprint $table): void {
            $table->string('installed_version')->nullable()->after('status');
            $table->string('upstream_version')->nullable()->after('installed_version');
        });
    }
};
