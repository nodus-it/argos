<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue triggers start automatic workspaces (instead of tasks): the link
 * remembers the workspace, when it was launched (never again for the same
 * issue) and the plan comment a 👍 approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->foreignUlid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('launched_at')->nullable();
            $table->string('plan_comment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_issue_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['launched_at', 'plan_comment_id']);
        });
    }
};
