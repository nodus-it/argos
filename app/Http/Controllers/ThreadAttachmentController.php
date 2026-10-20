<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Thread;
use App\Models\User;
use App\Services\Workspace\ThreadAttachmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves images attached to thread messages (members only). */
class ThreadAttachmentController extends Controller
{
    public function __invoke(Request $request, Thread $thread, string $file, ThreadAttachmentService $attachments): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $attachments->response($thread, $file, $user);
    }
}
