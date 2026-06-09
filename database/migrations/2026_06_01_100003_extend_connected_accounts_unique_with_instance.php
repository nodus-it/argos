<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow one OAuth account per (user, provider, instance) instead of one per
     * (user, provider), so a self-hosted GitLab can coexist with gitlab.com.
     * '' is the public-instance sentinel — kept NOT NULL so the unique index is
     * reliable on MariaDB (which treats NULLs as distinct).
     *
     * Index ordering matters on MariaDB: the user_id foreign key is supported by
     * the (user_id, provider) unique index. We must create the replacement
     * (user_id, provider, instance_url) unique — whose leftmost column is also
     * user_id — BEFORE dropping the old one, otherwise MariaDB refuses with
     * "Cannot drop index … needed in a foreign key constraint".
     */
    public function up(): void
    {
        DB::table('connected_accounts')->whereNull('instance_url')->update(['instance_url' => '']);

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('instance_url')->default('')->nullable(false)->change();
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->unique(['user_id', 'provider', 'instance_url']);
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        // Re-add the old unique (keeps user_id covered) before dropping the new one.
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'provider', 'instance_url']);
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('instance_url')->nullable()->change();
        });
    }
};
