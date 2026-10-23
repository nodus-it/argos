<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demos', function (Blueprint $table): void {
            // A demo now belongs to a task OR an interactive workspace.
            $table->foreignUlid('task_id')->nullable()->change();
            $table->foreignUlid('workspace_id')->nullable()->after('task_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('demos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
