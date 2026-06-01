<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            // Existing bindings authenticate via OAuth — keep that as the
            // default so the migration is non-destructive.
            $table->string('auth_method', 10)->default('oauth')->after('mode');
            $table->foreignUlid('provider_credential_id')
                ->nullable()
                ->after('connected_account_id')
                ->constrained('provider_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_credential_id');
            $table->dropColumn('auth_method');
        });
    }
};
