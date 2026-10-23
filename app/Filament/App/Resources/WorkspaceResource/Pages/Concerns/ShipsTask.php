<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages\Concerns;

use App\Enums\WorkspaceStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceShipService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * The workspace page's shipping: the commit and push dialogs (opened from
 * the action bar or `/ship`), filled with Claude's drafts once they open.
 */
trait ShipsTask
{
    /** Shown in the commit and push dialogs (changes, commits, gates). */
    public string $shipSummary = '';

    /** True while Claude drafts the text of the open commit or push dialog. */
    public bool $shipDrafting = false;

    /**
     * Action bar and `/ship`: open the commit or the push dialog — without a
     * step, whichever comes next (commit while something is uncommitted).
     */
    #[On('workspace-ship')]
    public function openShipDialog(?string $step = null): void
    {
        if ($step === null) {
            $task = $this->task();
            try {
                $step = $task !== null && app(WorkspaceShipService::class)->pending($task)['changed_files'] > 0 ? 'commit' : 'push';
            } catch (\Throwable) {
                $step = 'commit';
            }
        }
        $this->mountAction($step === 'push' ? 'push' : 'commit');
    }

    /** Commit what is not committed yet — message drafted by Claude, optionally pushing right after. */
    public function commitAction(): Action
    {
        return Action::make('commit')
            ->label(__('workspaces.ship.commit'))
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->visible(fn (): bool => $this->canShip())
            ->modalHeading(__('workspaces.ship.commit_heading'))
            ->modalWidth('2xl')
            // Opens at once; Claude's draft follows (loadShipDraft).
            ->fillForm(function (): array {
                $task = $this->requireTask();
                try {
                    $files = app(WorkspaceShipService::class)->pending($task)['changed_files'];
                } catch (\Throwable) {
                    $files = 0;
                }
                $this->shipSummary = trans_choice('workspaces.ship.commit_summary', $files, ['count' => $files]).$this->gateNote($task);
                $this->shipDrafting = true;

                return ['subject' => '', 'body' => '', 'then_push' => false];
            })
            ->modalContent(fn (): HtmlString => $this->draftingHint())
            ->modalDescription(fn (): string => $this->shipSummary)
            ->schema([
                TextInput::make('subject')
                    ->label(__('workspaces.ship.subject'))
                    ->required()
                    ->maxLength(120),
                Textarea::make('body')
                    ->label(__('workspaces.ship.body'))
                    ->rows(5),
                Toggle::make('then_push')
                    ->label(fn (): string => __($this->task()?->pr_number === null ? 'workspaces.ship.then_push_pr' : 'workspaces.ship.then_push')),
            ])
            ->modalSubmitActionLabel(__('workspaces.ship.commit'))
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    app(WorkspaceShipService::class)->commit($this->requireTask(), $user, (string) $data['subject'], (string) ($data['body'] ?? ''));
                    Notification::make()->title(__('workspaces.ship.committed'))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                $this->dispatch('argos-workspace-changed');
                if ((bool) ($data['then_push'] ?? false)) {
                    $this->replaceMountedAction('push');
                }
            });
    }

    /**
     * Push the branch. Without a pull request yet: open it, title and
     * description drafted by Claude from all commits; with one: confirm.
     */
    public function pushAction(): Action
    {
        $opens = fn (): bool => $this->task()?->pr_number === null;

        return Action::make('push')
            ->label(fn (): string => __($opens() ? 'workspaces.ship.push_pr' : 'workspaces.ship.push'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('primary')
            ->visible(fn (): bool => $this->canShip())
            ->modalHeading(fn (): string => __($opens() ? 'workspaces.ship.push_pr_heading' : 'workspaces.ship.push_heading'))
            ->modalWidth(fn (): string => $opens() ? '3xl' : 'md')
            ->fillForm(function () use ($opens): array {
                $task = $this->requireTask();
                try {
                    $pending = app(WorkspaceShipService::class)->pending($task);
                } catch (\Throwable) {
                    $pending = ['changed_files' => 0, 'ahead' => 0];
                }
                $this->shipSummary = trans_choice('workspaces.ship.push_summary', $pending['ahead'], ['count' => $pending['ahead']])
                    .($pending['changed_files'] > 0 ? ' '.trans_choice('workspaces.ship.uncommitted_left', $pending['changed_files'], ['count' => $pending['changed_files']]) : '')
                    .$this->gateNote($task);
                // A new pull request opens at once; Claude's draft follows (loadShipDraft).
                $this->shipDrafting = $opens();

                return ['title' => $task->title, 'body' => '', 'draft' => false];
            })
            ->modalContent(fn (): HtmlString => $this->draftingHint())
            ->modalDescription(fn (): string => $this->shipSummary)
            ->schema([
                TextInput::make('title')
                    ->label(__('workspaces.ship.pr_title'))
                    ->required($opens)
                    ->maxLength(200)
                    ->visible($opens),
                Textarea::make('body')
                    ->label(__('workspaces.ship.pr_body'))
                    ->rows(12)
                    ->visible($opens),
                Toggle::make('draft')
                    ->label(__('workspaces.ship.draft'))
                    ->visible($opens),
            ])
            ->modalSubmitActionLabel(fn (): string => __($opens() ? 'workspaces.ship.push_pr' : 'workspaces.ship.push'))
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    $url = app(WorkspaceShipService::class)->push($this->requireTask(), $user, (string) ($data['title'] ?? ''), (string) ($data['body'] ?? ''), (bool) ($data['draft'] ?? false));
                    Notification::make()->title(__('workspaces.ship.shipped', ['url' => $url]))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                }
                $this->dispatch('argos-workspace-changed');
                $this->refreshStatus();
            });
    }

    /**
     * Fill the open commit or push dialog with Claude's draft — called by the
     * dialog right after it opened, so it never waits for Claude. Fields the
     * person already typed into stay as they are.
     */
    public function loadShipDraft(): void
    {
        $index = array_key_last($this->mountedActions ?? []);
        $name = $index !== null ? ($this->mountedActions[$index]['name'] ?? null) : null;
        $task = $this->task();
        if (! $this->shipDrafting || ! in_array($name, ['commit', 'push'], true) || $task === null) {
            $this->shipDrafting = false;

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $service = app(WorkspaceShipService::class);
        try {
            $draft = $name === 'commit' ? $service->draft($task, $user) : $service->pullRequestDraft($task, $user);
        } catch (\Throwable) {
            $draft = [];
        }
        $this->shipDrafting = false;

        $first = $name === 'commit' ? 'subject' : 'title';
        if (trim((string) ($draft[$first] ?? '')) === '') {
            $this->shipSummary .= ' '.__($name === 'commit' ? 'workspaces.ship.no_draft' : 'workspaces.ship.no_pr_draft');

            return;
        }
        $data = $this->mountedActions[$index]['data'] ?? [];
        $untouched = [$first => $name === 'commit' ? '' : $task->title, 'body' => ''];
        foreach ($untouched as $field => $initial) {
            if (trim((string) ($data[$field] ?? '')) === $initial) {
                $this->mountedActions[$index]['data'][$field] = (string) $draft[$field];
            }
        }
    }

    /** "Claude drafts …" above the dialog's fields, which starts the draft when it appears. */
    private function draftingHint(): HtmlString
    {
        if (! $this->shipDrafting) {
            return new HtmlString('');
        }

        return new HtmlString('<p class="ag-note ag-muted" x-init="$wire.loadShipDraft()" data-testid="ship-drafting"><span class="ag-spin"></span> '.e(__('workspaces.ship.drafting')).'</p>');
    }

    private function canShip(): bool
    {
        return $this->workspace()->status === WorkspaceStatus::Running
            && ($task = $this->task()) !== null
            && (auth()->user()?->can('ship', $task) ?? false);
    }

    /** " Gates: …" for the commit and push dialogs, empty before the first run. */
    private function gateNote(Task $task): string
    {
        $outcome = app(WorkspaceGateService::class)->outcome($task);

        return $outcome === null ? '' : ' '.__($outcome['failed'] !== [] ? 'workspaces.gates.ship_warning' : 'workspaces.gates.ship_summary', ['summary' => $outcome['summary']]);
    }
}
