<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace setup: the branch of a tested but not yet merged setup (its
 * `.argos/` files apply to new tasks until the merge) and the outcome of the
 * last setup check (one entry per step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('setup_branch')->nullable()->after('setup_status');
            $table->json('setup_check')->nullable()->after('setup_branch');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['setup_branch', 'setup_check']);
        });
    }
};
