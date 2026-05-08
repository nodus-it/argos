<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignUlid('worker_stack_id_override')
                ->nullable()
                ->after('model_implement')
                ->constrained('worker_stacks')
                ->nullOnDelete();
            // worker_agent_name_override is a slug validated against App\Enums\AgentName.
            $table->string('worker_agent_name_override', 64)
                ->nullable()
                ->after('worker_stack_id_override');
            $table->json('worker_config_override')->nullable()->after('worker_agent_name_override');
            $table->foreignUlid('agent_credential_id')
                ->nullable()
                ->after('worker_config_override')
                ->constrained('agent_credentials')
                ->nullOnDelete();
            $table->json('agent_config')->nullable()->after('agent_credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_credential_id');
            $table->dropConstrainedForeignId('worker_stack_id_override');
            $table->dropColumn(['worker_agent_name_override', 'worker_config_override', 'agent_config']);
        });
    }
};
