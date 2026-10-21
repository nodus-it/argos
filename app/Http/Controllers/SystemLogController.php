<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SystemLogController extends Controller
{
    public function __invoke(): BinaryFileResponse
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
