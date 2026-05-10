<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // agent_name is a stable slug — see worker_image_builds migration.
            $table->string('agent_name', 64);
            $table->string('name');
            $table->text('credentials');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_name', 'name']);
            $table->index('agent_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_credentials');
    }
};
