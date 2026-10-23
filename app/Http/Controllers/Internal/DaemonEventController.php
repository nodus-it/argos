<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateWorkspaceDaemon;
use App\Models\Workspace;
use App\Services\Workspace\TaskEventIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives event batches from a workspace daemon and answers with the acks
 * the daemon uses to drop delivered events.
 */
class DaemonEventController extends Controller
{
    public function __invoke(Request $request, TaskEventIngestor $ingestor): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get(AuthenticateWorkspaceDaemon::ATTRIBUTE);

        $validated = $request->validate([
            'batch_id' => ['required', 'string', 'max:64'],
            'events' => ['present', 'array', 'max:500'],
            'events.*.task_id' => ['nullable', 'string', 'max:64'],
            'events.*.seq' => ['required', 'integer', 'min:1'],
            'events.*.type' => ['required', 'string', 'max:64'],
            'events.*.data' => ['nullable', 'array'],
        ]);

        /** @var list<array<string, mixed>> $events */
        $events = array_values($validated['events']);

        return response()->json(['acks' => (object) $ingestor->ingest($workspace, $events)]);
    }
}
