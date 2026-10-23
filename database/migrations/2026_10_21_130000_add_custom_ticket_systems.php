<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own ticket systems: a "custom" integration creates tasks over the REST API
 * and hears back about them through a signed webhook to this URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            $table->string('callback_url', 2000)->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            $table->dropColumn('callback_url');
        });
    }
};
