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
            $table->string('auth_method', 10)->default('pat')->after('platform');
            $table->foreignId('connected_account_id')
                ->nullable()
                ->after('auth_method')
                ->constrained('connected_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropForeign(['connected_account_id']);
            $table->dropColumn(['auth_method', 'connected_account_id']);
        });
    }
};
