<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-task demo access protection survives demo rebuilds (the demos row
        // is recreated on every deploy), so it lives on the task.
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('demo_access_mode', 16)->default('inherit')->after('worker_config_override');
            $table->string('demo_basic_password')->nullable()->after('demo_access_mode');
        });

        // The entry port is stored so a mode switch can rewrite the Traefik
        // route in place without a full redeploy.
        Schema::table('demos', function (Blueprint $table): void {
            $table->unsignedInteger('port')->nullable()->after('compose_project');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['demo_access_mode', 'demo_basic_password']);
        });

        Schema::table('demos', function (Blueprint $table): void {
            $table->dropColumn('port');
        });
    }
};
