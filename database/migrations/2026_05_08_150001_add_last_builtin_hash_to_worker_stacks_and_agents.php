<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the last_builtin_hash column used by the BuiltinSync to detect
 * whether a built-in row needs to be updated. Filename keeps the
 * "and_agents" suffix from the pre-refactor design — the agents table
 * was dropped before merge, so only worker_stacks gets the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_stacks', function (Blueprint $table): void {
            $table->string('last_builtin_hash', 64)->nullable()->after('has_update');
        });
    }

    public function down(): void
    {
        Schema::table('worker_stacks', function (Blueprint $table): void {
            $table->dropColumn('last_builtin_hash');
        });
    }
};
