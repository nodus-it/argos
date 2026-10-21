<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\ReviewComment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\ReviewService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\GateAnnotations;
use App\Support\ReviewDiff;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Review mode: the task's changes against its base branch in the
 * widened sidebar — toolbar (stats, seen progress, gate chips, split or
 * unified, maximise), file tree and the diff of one file with "seen",
 * expandable unchanged lines and previous/next file. Reads the diff only
 * while the review is open; the file is part of the URL.
 */
class ReviewPanel extends Component
{
    #[Locked]
    public string $workspaceId;

    public ?string $taskId = null;

    public bool $active = false;

    #[Url(as: 'file', except: null)]
    public ?string $file = null;

    public string $view = 'split';

    /** @var array<string, list<array{type: string, old_num: int, new_num: int, text: string}>> "path#hunk" => unchanged lines shown */
    public array $expanded = [];

    /** @var list<string> generated files whose diff was asked for anyway */
    public array $loadGenerated = [];

    /** @var array<string, int> path => diff lines shown (big diffs load in steps) */
    public array $lineLimit = [];

    /** Diff lines a file shows before "show more". */
    public const LINE_STEP = 400;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        $requested = request()->query('task');
        $this->taskId = is_string($requested) && $this->task($requested) !== null
            ? $requested
            : Task::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->value('id');
        $this->view = app(ReviewService::class)->diffView($this->user());
        $this->active = request()->query('review') === '1';
    }

    #[On('task-changed')]
    public function taskChanged(?string $id): void
    {
        $this->taskId = $id;
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
        $task = $this->taskId !== null ? $this->task($this->taskId) : null;
        if ($task === null) {
            return;
        }
        $seen
            ? app(ReviewService::class)->markSeen($task, $this->user(), $path, $hash)
            : app(ReviewService::class)->unmarkSeen($task, $this->user(), $path);
    }

    public function loadGeneratedDiff(string $path): void
    {
        $this->loadGenerated = array_values(array_unique([...$this->loadGenerated, $path]));
    }

    public function showMoreLines(string $path): void
    {
        $this->lineLimit[$path] = ($this->lineLimit[$path] ?? self::LINE_STEP) + self::LINE_STEP * 3;
    }

    /** Show the unchanged lines before hunk $index of $path. */
    public function expand(string $path, int $index): void
    {
        $file = $this->detail($path);
        $gap = $file['hunks'][$index]['gap'] ?? null;
        if ($gap === null) {
            return;
        }

        $task = $this->currentTask();
        if ($task === null) {
            return;
        }
        try {
            $content = app(WorkspaceDaemonClient::class)->for($this->workspace(), 15)->fileContent($task->slug, $path)['content'];
        } catch (DaemonTransportException) {
            return;
        }
        $this->expanded[$path.'#'.$index] = ReviewDiff::gapLines($content, $gap);
    }

    /** Collect a line comment (not sent yet — see ReviewCommentService). */
    public function addComment(string $path, string $side, int $start, int $end, string $body): void
    {
        $task = $this->taskId !== null ? $this->task($this->taskId) : null;
        if ($task === null || trim($body) === '') {
            return;
        }
        $file = $this->detail($path);
        if ($file === null) {
            return;
        }

        try {
            app(ReviewCommentService::class)->add($task, $this->user(), $path, $side, $start, $end, $body, $this->lineTexts($file, $side));
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();

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

    /** "Send anyway" on an outdated comment. */
    public function sendAnyway(string $id): void
    {
        $comment = $this->comment($id);
        if ($comment !== null) {
            app(ReviewCommentService::class)->sendAnyway($comment, $this->user());
            $this->dispatch('review-comments-changed');
        }
    }

    /** "Move to the new line": re-anchor an outdated comment on the current text. */
    public function moveComment(string $id): void
    {
        $comment = $this->comment($id);
        if ($comment === null) {
            return;
        }
        try {
            $content = app(WorkspaceDaemonClient::class)->for($this->workspace(), 15)->fileContent($comment->task->slug, $comment->path)['content'];
        } catch (DaemonTransportException) {
            return;
        }
        app(ReviewCommentService::class)->moveToCurrent($comment, $this->user(), ReviewCommentService::numbered($content));
        $this->dispatch('review-comments-changed');
    }

    /** "Let Claude fix it" on a gate finding: it joins the comment collection. */
    public function fixWithClaude(string $path, int $line, string $gate, string $message): void
    {
        $this->addComment($path, 'new', max(1, $line), max(1, $line), __('workspaces.review.fix_body', ['gate' => $gate, 'message' => $message]));
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

        $row = collect($files)->firstWhere('path', $this->file)
            ?? collect($files)->firstWhere('generated', false)
            ?? ($files[0] ?? null);
        $current = null;
        if ($row !== null) {
            try {
                $current = $this->detail($row['path'], $files) ?? [...$row, 'hunks' => [], 'skipped' => null, 'hidden' => 0];
            } catch (DaemonTransportException $e) {
                $error = $e->getMessage();
            }
        }
        $task = $this->taskId !== null ? $this->task($this->taskId) : null;
        if ($current !== null && $task !== null) {
            $this->reanchor($task, $current['path']);
        }
        $gateRun = $task !== null ? app(WorkspaceGateService::class)->latest($task) : null;
        $annotations = GateAnnotations::fromRun($gateRun);
        $phpstanHere = $current !== null ? collect($annotations['phpstan'][$current['path']] ?? []) : collect();
        $shown = $current !== null ? $this->shownLines($current) : [];
        $index = $current !== null ? array_search($current['path'], array_column($files, 'path'), true) : false;
        $seen = $task !== null
            ? app(ReviewService::class)->seen($task, $this->user(), array_column($files, 'hash', 'path'))
            : [];

        $comments = $task !== null ? app(ReviewCommentService::class)->open($task, $this->user()) : collect();
        $numbers = $comments->values()->mapWithKeys(fn (ReviewComment $c, int $i): array => [$c->id => $i + 1]);
        $here = $current !== null ? $comments->where('path', $current['path']) : collect();

        return view('livewire.workspace.review-panel', [
            'active' => true,
            'canWrite' => $task !== null && $this->user()->can('review', $task),
            'commentsAt' => $here->groupBy(fn (ReviewComment $c): string => $c->side.':'.$c->line_end),
            'commented' => $here->flatMap(fn (ReviewComment $c): array => array_map(fn (int $n): string => $c->side.':'.$n, range($c->line_start, $c->line_end)))->unique()->values()->all(),
            'numbers' => $numbers,
            'commentCount' => $comments->count(),
            'running' => $workspace->status === WorkspaceStatus::Running,
            'error' => $error,
            'files' => $files,
            'tree' => ReviewDiff::tree(array_values(array_filter($files, fn (array $f): bool => ! $f['generated']))),
            'generatedFiles' => array_values(array_filter($files, fn (array $f): bool => $f['generated'])),
            'current' => $current,
            'previous' => $index !== false && $index > 0 ? $files[$index - 1]['path'] : null,
            'next' => $index !== false && $index < count($files) - 1 ? $files[$index + 1]['path'] : null,
            'seen' => $seen,
            'additions' => array_sum(array_column($files, 'additions')),
            'deletions' => array_sum(array_column($files, 'deletions')),
            'gates' => array_values(array_filter(
                ContextPanel::gateRows($gateRun),
                fn (array $row): bool => in_array($row['state'], ['pass', 'fail', 'running'], true),
            )),
            'gateCounts' => ['phpstan' => array_sum(array_map('count', $annotations['phpstan'])), 'pint' => count($annotations['pint'])],
            'firstFinding' => array_key_first($annotations['phpstan']) ?? ($annotations['pint'][0] ?? null),
            'findingCounts' => GateAnnotations::counts($annotations),
            'annotationsAt' => $phpstanHere->filter(fn (array $a): bool => in_array($a['line'], $shown, true))->groupBy('line'),
            'outsideFindings' => $phpstanHere->reject(fn (array $a): bool => in_array($a['line'], $shown, true))->values(),
            'pintFlagged' => $current !== null && in_array($current['path'], $annotations['pint'], true),
            'firstChangedLine' => $current !== null ? (min($shown ?: [1])) : 1,
            'baseBranch' => $task !== null ? $task->base_branch : $workspace->default_branch,
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

    /** New-side line numbers the diff currently shows for a file (hunks + expanded gaps). */
    private function shownLines(array $file): array
    {
        return array_keys($this->lineTexts($file, 'new'));
    }

    /** Let the current file's comments follow Claude's edits (one daemon read). */
    private function reanchor(Task $task, string $path): void
    {
        $service = app(ReviewCommentService::class);
        if (! in_array($path, $service->anchoredPaths($task), true)) {
            return;
        }
        try {
            $content = app(WorkspaceDaemonClient::class)->for($this->workspace(), 15)->fileContent($task->slug, $path)['content'];
        } catch (DaemonTransportException) {
            return;
        }
        $service->reanchor($task, [$path => $content]);
    }

    private function comment(string $id): ?ReviewComment
    {
        return ReviewComment::query()->whereKey($id)->where('user_id', $this->user()->id)
            ->whereHas('task', fn ($q) => $q->where('workspace_id', $this->workspaceId))->first();
    }

    /**
     * The changed files (no diff text): path, status, counts, content hash.
     *
     * @return list<array{path: string, dir: string, name: string, status: string, additions: int, deletions: int, hash: string, generated: bool, binary: bool}>
     */
    private function files(): array
    {
        return array_map(fn (array $f): array => [
            'path' => $f['path'],
            'dir' => str_contains($f['path'], '/') ? dirname($f['path']) : '',
            'name' => basename($f['path']),
            'status' => $f['status'],
            'additions' => $f['additions'],
            'deletions' => $f['deletions'],
            'hash' => $f['hash'],
            'generated' => $f['generated'],
            'binary' => $f['binary'],
        ], ($task = $this->currentTask()) !== null ? app(WorkspaceDiffService::class)->summary($task)['files'] : []);
    }

    /**
     * One file with its hunks (fetched and cached per content hash), cut to
     * the shown line budget. Generated files only after "load anyway".
     *
     * @param  list<array<string, mixed>>|null  $files
     * @return array<string, mixed>|null
     */
    private function detail(string $path, ?array $files = null): ?array
    {
        $row = collect($files ?? $this->files())->firstWhere('path', $path);
        if ($row === null) {
            return null;
        }
        if ($row['binary']) {
            return [...$row, 'hunks' => [], 'skipped' => 'binary', 'hidden' => 0];
        }
        if ($row['generated'] && ! in_array($path, $this->loadGenerated, true)) {
            return [...$row, 'hunks' => [], 'skipped' => 'generated', 'hidden' => 0];
        }

        $task = $this->currentTask();
        $parsed = $task !== null ? app(WorkspaceDiffService::class)->fileDiff($task, $path, $row['hash']) : null;
        $hunks = $parsed !== null ? ReviewDiff::files([$parsed])[0]['hunks'] : [];

        // Big diffs: render up to the budget, the rest on request.
        $budget = $this->lineLimit[$path] ?? self::LINE_STEP;
        $kept = [];
        $hidden = 0;
        foreach ($hunks as $hunk) {
            $count = count($hunk['lines']);
            if ($budget <= 0) {
                $hidden += $count;

                continue;
            }
            if ($count > $budget) {
                $hidden += $count - $budget;
                $hunk['lines'] = array_slice($hunk['lines'], 0, $budget);
                $hunk['split'] = ReviewDiff::split($hunk['lines']);
            }
            $budget -= count($hunk['lines']);
            $kept[] = $hunk;
        }

        return [...$row, 'hunks' => $kept, 'skipped' => null, 'hidden' => $hidden];
    }

    private function currentTask(): ?Task
    {
        return $this->taskId !== null ? $this->task($this->taskId) : null;
    }

    private function task(string $id): ?Task
    {
        return Task::query()->where('workspace_id', $this->workspaceId)->find($id);
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
