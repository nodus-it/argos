<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('building');
            $table->string('url')->nullable();
            // The `docker compose -p` project name used to start/stop the demo.
            $table->string('compose_project')->nullable();
            $table->timestamp('ttl_until')->nullable();
            $table->longText('build_log')->nullable();
            $table->timestamps();

            $table->index('task_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demos');
    }
};
