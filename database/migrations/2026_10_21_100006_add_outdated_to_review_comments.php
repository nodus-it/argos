<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Send anyway" on an outdated comment: it goes out marked as referring to the previous version. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->boolean('outdated')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table): void {
            $table->dropColumn('outdated');
        });
    }
};
