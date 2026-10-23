<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comments taken over from the pull request: who wrote them there
 * and where, so the collected review names them and nothing is taken
 * over twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->string('source_id', 64)->nullable()->after('body');
            $table->string('source_author')->nullable()->after('source_id');
            $table->string('source_url', 2000)->nullable()->after('source_author');
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->dropColumn(['source_id', 'source_author', 'source_url']);
        });
    }
};
