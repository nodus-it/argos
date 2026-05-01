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
            $table->longText('concept_md')->nullable()->after('pr_url');
            $table->text('concept_notes')->nullable()->after('concept_md');
        });

        Schema::table('phase_runs', function (Blueprint $table) {
            $table->longText('concept_md')->nullable()->after('result_json');
            $table->text('concept_notes')->nullable()->after('concept_md');
            $table->longText('stream_log')->nullable()->after('concept_notes');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['concept_md', 'concept_notes']);
        });

        Schema::table('phase_runs', function (Blueprint $table) {
            $table->dropColumn(['concept_md', 'concept_notes', 'stream_log']);
        });
    }
};
