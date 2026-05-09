<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 final cleanup: the legacy worker_image string column is replaced
 * by the (worker_stack, worker_agent, agent_credential) trio resolved at
 * runtime by WorkerImageResolver. Nothing reads worker_image any more, so
 * the column is dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('worker_image');
        });

        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn('worker_image');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('worker_image')->nullable();
        });

        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->string('worker_image')->nullable();
        });
    }
};
