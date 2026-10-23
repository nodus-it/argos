<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace = project, conversation = task. The workspace absorbs the
 * project (repo, stack, preview, settings) and keeps one container; every
 * task gets its own branch, worktree, database and pull request, belongs to
 * one person and can have collaborators.
 *
 * Fresh start: projects, workspaces, threads, previews and everything that
 * hangs off them are deleted, not migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'review_comments', 'review_file_views', 'thread_reads', 'thread_checkpoints',
            'thread_approvals', 'thread_events', 'threads', 'workspace_gate_runs', 'demos',
            'workspace_members', 'workspaces', 'agent_versions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::table('external_issue_links')->delete();
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });

        DB::table('task_provider_bindings')->delete();
        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            $table->dropForeign(['repo_profile_id']);
            $table->dropColumn('repo_profile_id');
        });

        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\RepoProfile')->delete();
        DB::table('notifications')->delete();
        Schema::dropIfExists('repo_profiles');

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Frozen operational identity: volumes, container and host names.
            $table->string('slug', 48)->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Repository
            $table->string('repo_url');
            $table->string('platform', 16);
            $table->string('auth_method', 10)->default('pat');
            $table->text('token')->nullable();
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('default_branch')->default('main');
            // e.g. "{key}-{title}"; null = built-in pattern.
            $table->string('branch_pattern')->nullable();

            // Environment
            $table->string('worker_source', 16)->default('standard');
            $table->foreignUlid('worker_stack_id')->nullable()->constrained()->nullOnDelete();
            $table->text('composer_registries')->nullable();
            $table->text('worker_env')->nullable();
            $table->json('worker_services')->nullable();
            $table->json('worker_service_config')->nullable();
            $table->boolean('live_demo_enabled')->default(false);
            // missing | running | ready | failed — the setup task's verdict.
            $table->string('setup_status', 16)->nullable();

            // Resources and limits
            $table->unsignedTinyInteger('max_sessions')->default(3);
            $table->unsignedTinyInteger('max_previews')->default(3);
            $table->decimal('cpu_limit', 5, 2)->nullable();
            $table->unsignedInteger('memory_limit_mb')->nullable();
            // Worktree, database and preview of a finished task go after this.
            $table->unsignedSmallInteger('cleanup_after_days')->default(14);

            // Automation
            $table->json('auto_settings')->nullable();
            $table->foreignId('automation_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Container
            $table->string('status', 16)->default('stopped');
            $table->text('status_message')->nullable();
            $table->string('container_name')->nullable();
            $table->text('daemon_token');
            $table->timestamp('last_activity_at')->nullable();
            // base commit => missing | running | ready | failed (gate baselines, shared by tasks)
            $table->json('gate_baselines')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
        });

        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // admin | member | viewer
            $table->string('role', 16);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('task_provider_bindings', function (Blueprint $table): void {
            $table->foreignUlid('workspace_id')->after('id')->constrained()->cascadeOnDelete();
            // Set the ticket to done when the task's pull request is merged.
            $table->boolean('close_on_merge')->default(true)->after('filters');
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            // Worktree folder, database and preview names derive from it.
            $table->string('slug', 48);
            // task | setup
            $table->string('kind', 16)->default('task');
            // manual | api | mcp | issue
            $table->string('origin', 16)->default('manual');
            // Runs on the owner's subscription; collaborators write in it too.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('agent_credential_id')->nullable()->constrained()->nullOnDelete();
            // Follow-up of an earlier task (same ticket, summary as first message).
            $table->foreignUlid('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');

            // preparing | active | pr_open | done | cancelled (see TaskStatus)
            $table->string('status', 16)->default('preparing');
            // The daemon session: closed | starting | idle | running | waiting_input | error
            $table->string('session_status', 16)->default('closed');
            $table->string('auto_state', 32)->nullable();
            $table->json('auto_config')->nullable();
            $table->json('auto_counters')->nullable();
            $table->text('auto_message')->nullable();

            $table->string('permission_mode', 24)->default('default');
            $table->string('model');
            $table->string('claude_session_id')->nullable();
            $table->json('commands')->nullable();
            // Highest daemon event seq the manager has processed.
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            // An open daemon session holds one of the agent's slots.
            $table->boolean('session_open')->default(false);

            // Working copy
            $table->string('branch')->nullable();
            $table->string('base_branch');
            $table->string('base_commit', 64)->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('db_name', 64)->nullable();

            // Pull request
            $table->string('pr_url')->nullable();
            $table->unsignedBigInteger('pr_number')->nullable();
            // draft | open | merged | closed
            $table->string('pr_state', 16)->nullable();
            // {state: pending|success|failure, url}
            $table->json('pr_checks')->nullable();
            // approved | changes_requested | commented | null
            $table->string('pr_review_state', 24)->nullable();
            $table->boolean('pr_conflicts')->default(false);
            $table->timestamp('pr_synced_at')->nullable();
            $table->boolean('auto_merge')->default(false);
            $table->timestamp('shipped_at')->nullable();

            // {binding_id, provider, key, number, owner, project, title, url, body}
            $table->json('external_ref')->nullable();

            $table->text('restore_note')->nullable();
            $table->timestamp('done_at')->nullable();
            // Worktree, database and preview removed (chat stays).
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
            $table->index('owner_user_id');
            $table->index(['agent_credential_id', 'session_open']);
            $table->index('auto_state');
        });

        Schema::create('task_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_events', function (Blueprint $table): void {
            // Autoincrement id is the display order.
            $table->id();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Set for events reported by the daemon; second line of defence
            // against re-delivered batches.
            $table->unsignedBigInteger('daemon_seq')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'daemon_seq']);
            $table->index(['task_id', 'id']);
        });

        Schema::create('task_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->string('tool_use_id')->nullable();
            $table->string('kind', 16);
            $table->string('tool_name');
            $table->json('request');
            $table->string('status', 16)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
        });

        Schema::create('task_checkpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            // Number within the task; the git ref is refs/argos/checkpoints/<task>/<n>.
            $table->unsignedInteger('n');
            $table->string('ref');
            $table->string('sha', 64);
            $table->string('head_sha', 64)->nullable();
            $table->string('label', 120)->nullable();
            // The user message this checkpoint precedes (null: manual or pre-restore).
            $table->foreignId('task_event_id')->nullable()->constrained('task_events')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'n']);
            $table->index('task_event_id');
        });

        // How far each person has read a task (unread counters).
        Schema::create('task_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_event_id')->default(0);
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('review_file_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1000);
            $table->char('path_hash', 40);
            $table->char('file_hash', 40);
            $table->timestamps();

            $table->unique(['task_id', 'user_id', 'path_hash']);
        });

        Schema::create('review_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1000);
            $table->string('side', 8)->default('new');
            $table->unsignedInteger('line_start');
            $table->unsignedInteger('line_end');
            $table->char('anchor_hash', 40);
            $table->text('snippet');
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->boolean('outdated')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'user_id', 'status']);
        });

        Schema::create('workspace_gate_runs', function (Blueprint $table): void {
            // Doubles as the daemon's run id (log file suffix).
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('running');
            // gate => pass|fail|skip
            $table->json('gates')->nullable();
            // gate => last 8 KB of its log
            $table->json('logs')->nullable();
            // The base commit whose baseline the run compared against.
            $table->string('base_commit', 64)->nullable();
            // Baseline state when the run started: ready → only new failures count.
            $table->string('baseline', 16)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('demos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('url')->nullable();
            $table->string('compose_project')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->timestamp('ttl_until')->nullable();
            $table->longText('build_log')->nullable();
            $table->timestamps();
        });

        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->foreignUlid('task_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Issues assigned to this person may start auto-tasks on their subscription.
            $table->boolean('auto_task_consent')->default(false)->after('ui_prefs');
        });

        Schema::enableForeignKeyConstraints();
    }

    /** One-way: the project/thread model and its data are gone. */
    public function down(): void {}
};
