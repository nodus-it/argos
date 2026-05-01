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
            $table->longText('implement_summary_nontechnical')->nullable()->after('concept_notes');
            $table->longText('implement_summary_technical')->nullable()->after('implement_summary_nontechnical');
            $table->text('implement_notes')->nullable()->after('implement_summary_technical');
        });

        Schema::table('phase_runs', function (Blueprint $table) {
            $table->longText('implement_summary_nontechnical')->nullable()->after('stream_log');
            $table->longText('implement_summary_technical')->nullable()->after('implement_summary_nontechnical');
            $table->text('implement_notes')->nullable()->after('implement_summary_technical');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['implement_summary_nontechnical', 'implement_summary_technical', 'implement_notes']);
        });

        Schema::table('phase_runs', function (Blueprint $table) {
            $table->dropColumn(['implement_summary_nontechnical', 'implement_summary_technical', 'implement_notes']);
        });
    }
};
