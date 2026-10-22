<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The newest Argos log (app, queue and scheduler share the log directory),
 * falling back to Laravel's own log.
 */
class SystemLogController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $dir = dirname((string) config('logging.channels.argos.path'));
        // The argos channel rotates daily: argos-YYYY-MM-DD.log.
        $daily = glob($dir.'/argos-*.log') ?: [];
        rsort($daily);
        $logPath = $daily[0] ?? (string) config('logging.channels.single.path');

        abort_unless(is_file($logPath), 404, 'Log file not found');

        return response()->download($logPath, basename($logPath), [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
