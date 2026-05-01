<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->enum('workflow_status', [
                'draft',
                'concept_running',
                'concept_review',
                'implement_running',
                'in_review',
                'completed',
                'failed',
            ])->default('draft')->after('pr_url');

            $table->boolean('auto_concept')->default(false)->after('workflow_status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['workflow_status', 'auto_concept']);
        });
    }
};
