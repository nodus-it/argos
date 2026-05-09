<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the 8-char stack-hash a build was made against, so we can detect
 * "stack dockerfile changed since this build" without having to re-derive
 * it from the tag string at query time.
 *
 * Backfills existing rows by parsing the hash out of `tag`. The tag format
 * (`argos-worker:{name}-{8hex}-{agent}-{version}`) is stable and produced
 * exclusively by WorkerImageResolver, so the regex is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_image_builds', function (Blueprint $table): void {
            $table->string('stack_hash', 16)->nullable()->after('agent_name');
            $table->index('stack_hash');
        });

        foreach (DB::table('worker_image_builds')->select('id', 'tag')->get() as $row) {
            if (preg_match('/-([a-f0-9]{8})-/', $row->tag, $m) === 1) {
                DB::table('worker_image_builds')
                    ->where('id', $row->id)
                    ->update(['stack_hash' => $m[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('worker_image_builds', function (Blueprint $table): void {
            $table->dropIndex(['stack_hash']);
            $table->dropColumn('stack_hash');
        });
    }
};
