<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Picture and profile link of who wrote a taken-over pull request comment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->string('source_avatar', 2000)->nullable()->after('source_url');
            $table->string('source_profile', 2000)->nullable()->after('source_avatar');
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->dropColumn(['source_avatar', 'source_profile']);
        });
    }
};
