<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('pr_url')->nullable()->after('last_activity_at');
            $table->unsignedBigInteger('pr_number')->nullable()->after('pr_url');
            // open | merged | closed (provider-neutral, see PullRequestInfo)
            $table->string('pr_state', 16)->nullable()->after('pr_number');
            $table->timestamp('pr_synced_at')->nullable()->after('pr_state');
            $table->timestamp('shipped_at')->nullable()->after('pr_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['pr_url', 'pr_number', 'pr_state', 'pr_synced_at', 'shipped_at']);
        });
    }
};
