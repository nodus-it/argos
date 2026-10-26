<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to task messages, stored privately per workspace and task.
 * Images reach Claude as image blocks; other files are written into the
 * task folder by the daemon. Members can open both again.
 */
class TaskAttachmentService
{
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public const MAX_FILES = 5;

    private const DISK = 'local';

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{media_type: string, data: string, path: string}>
     */
    public function store(Task $task, array $files): array
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
            $path = $this->directory($task).'/'.Str::ulid().'.'.$extension;
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

            $images[] = ['media_type' => $mime, 'data' => base64_encode((string) Storage::disk(self::DISK)->get($path)), 'path' => $path];
        }

        return $images;
    }

    /**
     * Keep the attachments that are no image; Claude reads them from the task folder.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, path: string}>
     */
    public function storeFiles(Task $task, array $files): array
    {
        $stored = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (in_array((string) $file->getMimeType(), self::MIME_TYPES, true)) {
                continue;
            }
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $path = $this->directory($task).'/'.Str::ulid().(preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '');
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));
            $stored[] = ['name' => $file->getClientOriginalName(), 'path' => $path];
        }

        return $stored;
    }

    /** Stream an attachment to a member of the task's workspace; anything but an image downloads. */
    public function response(Task $task, string $file, User $user): StreamedResponse
    {
        Gate::forUser($user)->authorize('view', $task);
        abort_unless(preg_match('/^[0-9A-Za-z]{26}(\.[a-z0-9]{1,10})?$/', $file) === 1, 404);

        $path = $this->directory($task).'/'.$file;
        abort_unless(Storage::disk(self::DISK)->exists($path), 404);

        $headers = ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff'];
        if (preg_match('/\.(png|jpg|gif|webp)$/', $file) === 1) {
            return Storage::disk(self::DISK)->response($path, null, $headers);
        }
        $name = $this->originalName($task, $file);

        return Storage::disk(self::DISK)->download($path, $name, $headers);
    }

    /**
     * Read stored images back (a queued message is delivered later).
     *
     * @param  list<array{media_type?: string, path?: string|null}>  $images
     * @return list<array{media_type: string, data: string}>
     */
    public function load(array $images): array
    {
        $loaded = [];
        foreach ($images as $image) {
            $path = (string) ($image['path'] ?? '');
            if ($path !== '' && str_starts_with($path, 'workspace-uploads/') && Storage::disk(self::DISK)->exists($path)) {
                $loaded[] = ['media_type' => (string) ($image['media_type'] ?? 'image/png'), 'data' => base64_encode((string) Storage::disk(self::DISK)->get($path))];
            }
        }

        return $loaded;
    }

    /**
     * Read stored files back for the daemon.
     *
     * @param  list<array{name?: string, path?: string|null}>  $files
     * @return list<array{name: string, data: string}>
     */
    public function loadFiles(array $files): array
    {
        $loaded = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '' && str_starts_with($path, 'workspace-uploads/') && Storage::disk(self::DISK)->exists($path)) {
                $loaded[] = ['name' => (string) ($file['name'] ?? basename($path)), 'data' => base64_encode((string) Storage::disk(self::DISK)->get($path))];
            }
        }

        return $loaded;
    }

    /** The name the file was uploaded with, from the message that carries it. */
    private function originalName(Task $task, string $file): string
    {
        $event = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)
            ->where('payload', 'like', '%'.$file.'%')->latest('id')->first();
        foreach ((array) ($event?->payload['files'] ?? []) as $stored) {
            if (is_array($stored) && basename((string) ($stored['path'] ?? '')) === $file) {
                return (string) ($stored['name'] ?? $file);
            }
        }

        return $file;
    }

    /** Remove every attachment of a workspace (on delete). */
    public function purgeWorkspace(string $workspaceId): void
    {
        Storage::disk(self::DISK)->deleteDirectory('workspace-uploads/'.$workspaceId);
    }

    private function directory(Task $task): string
    {
        return 'workspace-uploads/'.$task->workspace_id.'/'.$task->id;
    }
}
