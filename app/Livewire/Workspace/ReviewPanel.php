<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\ReviewComment;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\ReviewService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\ReviewDiff;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Review mode: the workspace's changes against its base branch in the
 * widened sidebar — toolbar (stats, seen progress, gate chips, split or
 * unified, maximise), file tree and the diff of one file with "seen",
 * expandable unchanged lines and previous/next file. Reads the diff only
 * while the review is open; the file is part of the URL.
 */
class ReviewPanel extends Component
{
    #[Locked]
    public string $workspaceId;

    public ?string $threadId = null;

    public bool $active = false;

    #[Url(as: 'file', except: null)]
    public ?string $file = null;

    public string $view = 'split';

    /** @var array<string, list<array{type: string, old_num: int, new_num: int, text: string}>> "path#hunk" => unchanged lines shown */
    public array $expanded = [];

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        $requested = request()->query('thread');
        $this->threadId = is_string($requested) && $this->thread($requested) !== null
            ? $requested
            : Thread::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->value('id');
        $this->view = app(ReviewService::class)->diffView($this->user());
        $this->active = request()->query('review') === '1';
    }

    #[On('thread-changed')]
    public function threadChanged(?string $id): void
    {
        $this->threadId = $id;
    }

    public function open(?string $file = null): void
    {
        $this->active = true;
        if ($file !== null && $file !== '') {
            $this->file = $file;
        }
    }

    public function close(): void
    {
        $this->active = false;
    }

    public function selectFile(string $path): void
    {
        $this->file = $path;
    }

    public function setView(string $view): void
    {
        if (in_array($view, ReviewService::VIEWS, true)) {
            $this->view = $view;
            app(ReviewService::class)->setDiffView($this->user(), $view);
        }
    }

    public function toggleSeen(string $path, string $hash, bool $seen): void
    {
        $thread = $this->threadId !== null ? $this->thread($this->threadId) : null;
        if ($thread === null) {
            return;
        }
        $seen
            ? app(ReviewService::class)->markSeen($thread, $this->user(), $path, $hash)
            : app(ReviewService::class)->unmarkSeen($thread, $this->user(), $path);
    }

    /** Show the unchanged lines before hunk $index of $path. */
    public function expand(string $path, int $index): void
    {
        $file = collect($this->files())->firstWhere('path', $path);
        $gap = $file['hunks'][$index]['gap'] ?? null;
        if ($gap === null) {
            return;
        }

        try {
            $content = app(WorkspaceDaemonClient::class)->for($this->workspace(), 15)->fileContent($path)['content'];
        } catch (DaemonTransportException) {
            return;
        }
        $this->expanded[$path.'#'.$index] = ReviewDiff::gapLines($content, $gap);
    }

    /** Collect a line comment (not sent yet — see ReviewCommentService). */
    public function addComment(string $path, string $side, int $start, int $end, string $body): void
    {
        $thread = $this->threadId !== null ? $this->thread($this->threadId) : null;
        if ($thread === null || trim($body) === '') {
            return;
        }
        $file = collect($this->files())->firstWhere('path', $path);
        if ($file === null) {
            return;
        }

        try {
            app(ReviewCommentService::class)->add($thread, $this->user(), $path, $side, $start, $end, $body, $this->lineTexts($file, $side));
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();

            return;
        }
        $this->dispatch('review-comments-changed');
    }

    public function updateComment(string $id, string $body): void
    {
        $comment = $this->comment($id);
        if ($comment !== null && trim($body) !== '') {
            app(ReviewCommentService::class)->edit($comment, $this->user(), $body);
            $this->dispatch('review-comments-changed');
        }
    }

    public function discardComment(string $id): void
    {
        $comment = $this->comment($id);
        if ($comment !== null) {
            app(ReviewCommentService::class)->discard($comment, $this->user());
            $this->dispatch('review-comments-changed');
        }
    }

    /** The composer changed the collection (removed a chip, sent the review). */
    #[On('review-comments-changed')]
    public function commentsChanged(): void {}

    public function render(): View
    {
        $workspace = $this->workspace();
        if (! $this->active) {
            return view('livewire.workspace.review-panel', ['active' => false]);
        }

        $error = null;
        $files = [];
        if ($workspace->status === WorkspaceStatus::Running) {
            try {
                $files = $this->files();
            } catch (DaemonTransportException $e) {
                $error = $e->getMessage();
            }
        }

        $current = collect($files)->firstWhere('path', $this->file) ?? ($files[0] ?? null);
        $index = $current !== null ? array_search($current['path'], array_column($files, 'path'), true) : false;
        $thread = $this->threadId !== null ? $this->thread($this->threadId) : null;
        $seen = $thread !== null
            ? app(ReviewService::class)->seen($thread, $this->user(), array_column($files, 'hash', 'path'))
            : [];

        $comments = $thread !== null ? app(ReviewCommentService::class)->open($thread, $this->user()) : collect();
        $numbers = $comments->values()->mapWithKeys(fn (ReviewComment $c, int $i): array => [$c->id => $i + 1]);
        $here = $current !== null ? $comments->where('path', $current['path']) : collect();

        return view('livewire.workspace.review-panel', [
            'active' => true,
            'canWrite' => $workspace->roleOf($this->user())?->canOperate() ?? false,
            'commentsAt' => $here->groupBy(fn (ReviewComment $c): string => $c->side.':'.$c->line_end),
            'commented' => $here->flatMap(fn (ReviewComment $c): array => array_map(fn (int $n): string => $c->side.':'.$n, range($c->line_start, $c->line_end)))->unique()->values()->all(),
            'numbers' => $numbers,
            'commentCount' => $comments->count(),
            'running' => $workspace->status === WorkspaceStatus::Running,
            'error' => $error,
            'files' => $files,
            'tree' => ReviewDiff::tree($files),
            'current' => $current,
            'previous' => $index !== false && $index > 0 ? $files[$index - 1]['path'] : null,
            'next' => $index !== false && $index < count($files) - 1 ? $files[$index + 1]['path'] : null,
            'seen' => $seen,
            'additions' => array_sum(array_column($files, 'additions')),
            'deletions' => array_sum(array_column($files, 'deletions')),
            'gates' => array_values(array_filter(
                ContextPanel::gateRows(app(WorkspaceGateService::class)->latest($workspace)),
                fn (array $row): bool => in_array($row['state'], ['pass', 'fail', 'running'], true),
            )),
            'baseBranch' => $workspace->base_branch,
        ]);
    }

    /**
     * Text of every line the diff shows for one side (plus expanded gaps).
     *
     * @param  array<string, mixed>  $file
     * @return array<int, string>
     */
    private function lineTexts(array $file, string $side): array
    {
        $key = $side === 'old' ? 'old_num' : 'new_num';
        $skip = $side === 'old' ? 'add' : 'del';
        $lines = [];
        foreach ($file['hunks'] as $hunk) {
            foreach ([...($this->expanded[$file['path'].'#'.$hunk['index']] ?? []), ...$hunk['lines']] as $line) {
                if ($line['type'] !== $skip && $line[$key] !== null) {
                    $lines[(int) $line[$key]] = (string) $line['text'];
                }
            }
        }
        ksort($lines);

        return $lines;
    }

    private function comment(string $id): ?ReviewComment
    {
        return ReviewComment::query()->whereKey($id)->where('user_id', $this->user()->id)
            ->whereHas('thread', fn ($q) => $q->where('workspace_id', $this->workspaceId))->first();
    }

    /** @return list<array<string, mixed>> */
    private function files(): array
    {
        return ReviewDiff::files(app(WorkspaceDiffService::class)->forWorkspace($this->workspace())['files']);
    }

    private function thread(string $id): ?Thread
    {
        return Thread::query()->where('workspace_id', $this->workspaceId)->find($id);
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->workspaceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
