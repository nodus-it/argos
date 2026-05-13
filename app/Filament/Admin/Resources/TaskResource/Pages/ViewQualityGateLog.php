<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
use App\Models\PhaseRun;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class ViewQualityGateLog extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-quality-gate-log';

    public Task $task;

    public string $phase = 'implement';

    /** @var array<int, string> available gate-log keys for the active PhaseRun (e.g. "pest", "pest.fix1") */
    public array $availableKeys = [];

    public string $activeKey = '';

    public string $logContent = '';

    public ?int $iteration = null;

    /** @var array<int, array{phase: string, iteration: int, has_logs: bool}> tabs across phase runs that produced gate logs */
    public array $phaseTabs = [];

    public function mount(string $record): void
    {
        $this->task = Task::findOrFail($record);

        $this->phaseTabs = $this->collectPhaseTabs();

        $requested = request()->query('phase');
        if (is_string($requested) && in_array($requested, ['implement', 'respond'], true)) {
            $this->phase = $requested;
        } elseif ($this->phaseTabs !== []) {
            $this->phase = $this->phaseTabs[0]['phase'];
        }

        $this->reload();

        $requestedKey = request()->query('key');
        if (is_string($requestedKey) && in_array($requestedKey, $this->availableKeys, true)) {
            $this->activeKey = $requestedKey;
            $this->refreshLogContent();
        }
    }

    public function switchPhase(string $phase): void
    {
        if (! in_array($phase, ['implement', 'respond'], true)) {
            return;
        }
        $this->phase = $phase;
        $this->reload();
    }

    public function selectKey(string $key): void
    {
        if (! in_array($key, $this->availableKeys, true)) {
            return;
        }
        $this->activeKey = $key;
        $this->refreshLogContent();
    }

    public function getTitle(): string
    {
        return __('tasks.view.quality_gate_log.title', ['name' => $this->task->name]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => __('tasks.view.quality_gate_log.breadcrumb_tasks'),
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => __('tasks.view.quality_gate_log.breadcrumb_self'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('tasks.view.quality_gate_log.back'))
                ->color('gray')
                ->url(fn (): string => TaskResource::getUrl('view', ['record' => $this->task])),
        ];
    }

    /**
     * @return array<int, array{phase: string, iteration: int, has_logs: bool}>
     */
    private function collectPhaseTabs(): array
    {
        $tabs = [];
        foreach (['implement', 'respond'] as $phase) {
            $latest = $this->latestPhaseRunWithLogs($phase);
            if ($latest === null) {
                continue;
            }
            $tabs[] = [
                'phase' => $phase,
                'iteration' => $latest->iteration,
                'has_logs' => true,
            ];
        }

        return $tabs;
    }

    private function latestPhaseRunWithLogs(string $phase): ?PhaseRun
    {
        return $this->task->phaseRuns()
            ->where('phase', $phase)
            ->whereNotNull('quality_gate_logs')
            ->orderByDesc('iteration')
            ->orderByDesc('created_at')
            ->first();
    }

    private function reload(): void
    {
        $run = $this->latestPhaseRunWithLogs($this->phase);
        if ($run === null) {
            $this->availableKeys = [];
            $this->activeKey = '';
            $this->logContent = '';
            $this->iteration = null;

            return;
        }

        $this->iteration = $run->iteration;
        $logs = $run->quality_gate_logs ?? [];
        $this->availableKeys = $this->sortKeys(array_keys($logs));
        $this->activeKey = $this->defaultKey($run, $logs);
        $this->logContent = $logs[$this->activeKey] ?? '';
    }

    private function refreshLogContent(): void
    {
        $run = $this->latestPhaseRunWithLogs($this->phase);
        if ($run === null) {
            $this->logContent = '';

            return;
        }
        $logs = $run->quality_gate_logs ?? [];
        $this->logContent = $logs[$this->activeKey] ?? '';
    }

    /**
     * Prefer the gate that the worker reported as the failing one — usually
     * the last fix-iteration of that gate has the most useful diagnosis.
     *
     * @param  array<string, string>  $logs
     */
    private function defaultKey(PhaseRun $run, array $logs): string
    {
        $failed = $run->result_json['failed_gate'] ?? null;
        if (is_string($failed) && $failed !== '') {
            $matches = array_values(array_filter(
                array_keys($logs),
                fn (string $k): bool => $k === $failed || str_starts_with($k, $failed.'.'),
            ));
            if ($matches !== []) {
                $sorted = $this->sortKeys($matches);

                return end($sorted) ?: $sorted[0];
            }
        }

        $sorted = $this->sortKeys(array_keys($logs));

        return $sorted[0] ?? '';
    }

    /**
     * Sort keys so that initial gate comes before its fix-iterations,
     * and gates appear in a stable order.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function sortKeys(array $keys): array
    {
        usort($keys, function (string $a, string $b): int {
            [$gateA, $iterA] = $this->splitKey($a);
            [$gateB, $iterB] = $this->splitKey($b);

            return [$gateA, $iterA] <=> [$gateB, $iterB];
        });

        return $keys;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitKey(string $key): array
    {
        if (! str_contains($key, '.')) {
            return [$key, 0];
        }
        [$gate, $suffix] = explode('.', $key, 2);
        if (preg_match('/^fix(\d+)$/', $suffix, $m) === 1) {
            return [$gate, (int) $m[1]];
        }

        return [$gate, 0];
    }
}
