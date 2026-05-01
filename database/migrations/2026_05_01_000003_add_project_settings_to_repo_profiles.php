<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table) {
            $table->string('worker_image')->nullable()->after('platform');
            $table->boolean('auto_concept')->default(false)->after('worker_image');
            $table->boolean('auto_pr')->default(false)->after('auto_concept');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table) {
            $table->dropColumn(['worker_image', 'auto_concept', 'auto_pr']);
        });
    }
};
