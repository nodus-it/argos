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
            $table->string('worker_source', 16)->default('standard')->after('worker_image');
            $table->foreignUlid('worker_stack_id')
                ->nullable()
                ->after('worker_source')
                ->constrained('worker_stacks')
                ->nullOnDelete();
            $table->foreignUlid('worker_agent_id')
                ->nullable()
                ->after('worker_stack_id')
                ->constrained('worker_agents')
                ->nullOnDelete();
            $table->json('worker_config')->nullable()->after('worker_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('repo_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('worker_agent_id');
            $table->dropConstrainedForeignId('worker_stack_id');
            $table->dropColumn(['worker_source', 'worker_config']);
        });
    }
};
