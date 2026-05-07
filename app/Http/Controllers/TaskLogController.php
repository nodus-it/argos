<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaskLogController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_PHASES = ['concept', 'implement', 'push', 'diff', 'respond', 'commit-message'];

    public function downloadPhaseLog(Task $task, Request $request): BinaryFileResponse
    {
        $phase = (string) $request->query('phase', 'concept');

        abort_unless(in_array($phase, self::ALLOWED_PHASES, true), 400, 'Invalid phase');

        $configDir = (string) config('argos.config_dir');
        $logPath = "{$configDir}/tasks/{$task->name}/{$phase}.bg.log";

        abort_unless(file_exists($logPath), 404, 'Log file not found');

        $filename = sprintf('argos-%s-%s-%s.log', $task->name, $phase, now()->format('Y-m-d'));

        return response()->download($logPath, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function downloadAppLog(): BinaryFileResponse
    {
        $logPath = storage_path('logs/argos.log');

        if (! file_exists($logPath)) {
            $logPath = storage_path('logs/laravel.log');
        }

        abort_unless(file_exists($logPath), 404, 'Log file not found');

        $filename = sprintf('argos-app-%s.log', now()->format('Y-m-d'));

        return response()->download($logPath, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
