<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images attached to thread messages: stored privately per workspace and
 * thread, handed to Claude as base64 image blocks, served back to members.
 */
class ThreadAttachmentService
{
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public const MAX_FILES = 5;

    private const DISK = 'local';

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{media_type: string, data: string, path: string}>
     */
    public function store(Thread $thread, array $files): array
    {
        $images = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $mime = (string) $file->getMimeType();
            if (! in_array($mime, self::MIME_TYPES, true)) {
                continue;
            }
            $extension = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                default => 'webp',
            };
            $path = $this->directory($thread).'/'.Str::ulid().'.'.$extension;
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

            $images[] = ['media_type' => $mime, 'data' => base64_encode((string) Storage::disk(self::DISK)->get($path)), 'path' => $path];
        }

        return $images;
    }

    /** Stream an attachment to a member of the thread's workspace. */
    public function response(Thread $thread, string $file, User $user): StreamedResponse
    {
        Gate::forUser($user)->authorize('view', $thread);
        abort_unless(preg_match('/^[0-9A-Za-z]{26}\.(png|jpg|gif|webp)$/', $file) === 1, 404);

        $path = $this->directory($thread).'/'.$file;
        abort_unless(Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Remove every attachment of a workspace (on delete). */
    public function purgeWorkspace(string $workspaceId): void
    {
        Storage::disk(self::DISK)->deleteDirectory('workspace-uploads/'.$workspaceId);
    }

    private function directory(Thread $thread): string
    {
        return 'workspace-uploads/'.$thread->workspace_id.'/'.$thread->id;
    }
}
