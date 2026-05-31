<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_issue_links', function (Blueprint $table): void {
            // Provider id of the concept comment Argos posted on the issue, so a
            // 👍 reaction on it can be polled to approve and start implement.
            $table->string('concept_comment_id')->nullable()->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropColumn('concept_comment_id');
        });
    }
};
