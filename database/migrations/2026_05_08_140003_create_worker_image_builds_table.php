<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_image_builds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('worker_stack_id')
                ->constrained('worker_stacks')
                ->cascadeOnDelete();
            // agent_name is a stable slug; the runner class lives in code,
            // so a foreign key would only point at duplicated metadata.
            // Validation happens at the App\Enums\AgentName cast layer.
            $table->string('agent_name', 64);
            $table->string('tag');
            $table->string('status', 16)->default('queued');
            $table->longText('build_log')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['worker_stack_id', 'agent_name', 'tag']);
            $table->index(['status', 'updated_at']);
            $table->index('agent_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_image_builds');
    }
};
