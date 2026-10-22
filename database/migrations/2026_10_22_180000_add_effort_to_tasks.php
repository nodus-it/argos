<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reasoning effort of a task (Claude Code's /effort); null: the model's default. */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('effort', 10)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('effort');
        });
    }
};
