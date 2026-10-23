<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\TaskAttachmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves images attached to task messages (members only). */
class TaskAttachmentController extends Controller
{
    public function __invoke(Request $request, Task $task, string $file, TaskAttachmentService $attachments): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $attachments->response($task, $file, $user);
    }
}
