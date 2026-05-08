<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->string('model_concept')->nullable()->after('worker_image');
            $table->string('model_implement')->nullable()->after('model_concept');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropColumn(['model_concept', 'model_implement']);
        });
    }
};
