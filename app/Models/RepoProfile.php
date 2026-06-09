<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\BackingService;
use App\Enums\ClaudeModel;
use App\Enums\GitProvider;
use App\Enums\WorkerSource;
use App\Enums\WorkflowStatus;
use App\Services\OAuth\TokenRefresher;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $url
 * @property string|null $token
 * @property string $default_branch
 * @property GitProvider $platform
 * @property AuthMethod $auth_method
 * @property int|null $connected_account_id
 * @property WorkerSource $worker_source
 * @property string|null $worker_stack_id
 * @property AgentName|null $worker_agent_name
 * @property array<string, mixed>|null $worker_config
 * @property array<int, array{host: string, username?: string|null, token: string}>|null $composer_registries
 * @property array<int, array{name: string, value: string}>|null $worker_env
 * @property array<int, string>|null $worker_services
 * @property string|null $model_concept
 * @property string|null $model_implement
 * @property int|null $max_turns_concept
 * @property int|null $max_turns_implement
 * @property bool $auto_concept
 * @property bool $auto_pr
 * @property bool $live_demo_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Task> $tasks
 * @property-read ConnectedAccount|null $connectedAccount
 * @property-read WorkerStack|null $workerStack
 * @property-read Collection<int, TaskProviderBinding> $taskProviderBindings
 */
class RepoProfile extends Model implements HasApiTokensContract
{
    // Project-bound Sanctum API tokens: one token = one project, fine-scoped
    // (e.g. CI). $repoProfile->createToken('ci', ['tasks:write']).
    use HasApiTokens;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'url',
        'token',
        'default_branch',
        'platform',
        'auth_method',
        'connected_account_id',
        'worker_source',
        'worker_stack_id',
        'worker_agent_name',
        'worker_config',
        'composer_registries',
        'worker_env',
        'worker_services',
        'model_concept',
        'model_implement',
        'max_turns_concept',
        'max_turns_implement',
        'auto_concept',
        'auto_pr',
        'live_demo_enabled',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'platform' => GitProvider::class,
            'auth_method' => AuthMethod::class,
            'worker_source' => WorkerSource::class,
            'worker_agent_name' => AgentName::class,
            'worker_config' => 'array',
            'composer_registries' => 'encrypted:array',
            'worker_env' => 'encrypted:array',
            'worker_services' => 'array',
            'max_turns_concept' => 'integer',
            'max_turns_implement' => 'integer',
            'auto_concept' => 'boolean',
            'auto_pr' => 'boolean',
            'live_demo_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Tasks that are not in a terminal workflow state — drives the API's
     * "open_tasks" count.
     *
     * @return HasMany<Task, $this>
     */
    public function openTasks(): HasMany
    {
        return $this->tasks()->whereNotIn('workflow_status', [
            WorkflowStatus::Completed->value,
            WorkflowStatus::Failed->value,
        ]);
    }

    /**
     * @return HasMany<TaskProviderBinding, $this>
     */
    public function taskProviderBindings(): HasMany
    {
        return $this->hasMany(TaskProviderBinding::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * @return BelongsTo<WorkerStack, $this>
     */
    public function workerStack(): BelongsTo
    {
        return $this->belongsTo(WorkerStack::class);
    }

    /**
     * The backing services this project opts into for its worker test runs,
     * as resolved enum cases (unknown stored values are dropped).
     *
     * @return list<BackingService>
     */
    public function backingServices(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?BackingService => BackingService::tryFrom($value),
            $this->worker_services ?? [],
        )));
    }

    /**
     * Returns the configured model ID string for the given phase,
     * falling back to the hardcoded default when null.
     */
    public function modelForPhase(string $phase): string
    {
        $configured = match ($phase) {
            'concept' => $this->model_concept,
            'implement' => $this->model_implement,
            default => null,
        };

        return $configured ?? ClaudeModel::default($phase)->value;
    }

    /**
     * Resolve the repository token for this profile.
     * Returns the PAT when auth_method is 'pat', or the OAuth token from the
     * linked ConnectedAccount when auth_method is 'oauth'. For OAuth, refreshes
     * the access token via the provider's refresh_token flow if it is at risk
     * of expiring within the worker's job timeout.
     *
     * @throws \RuntimeException when no token is configured or the refresh fails
     */
    public function resolveToken(): string
    {
        if ($this->auth_method !== AuthMethod::OAuth) {
            if ($this->token !== null && $this->token !== '') {
                return $this->token;
            }

            throw new \RuntimeException(
                'Kein PAT konfiguriert. Bitte Token im Projekt hinterlegen oder GitHub-Account verknüpfen.'
            );
        }

        $account = $this->connectedAccount;
        if ($account === null) {
            throw new \RuntimeException(
                'Kein OAuth-Account verknüpft. Bitte GitHub-Account verbinden.'
            );
        }

        $account = app(TokenRefresher::class)->refreshIfNeeded($account);

        return $account->token;
    }

    /**
     * Extracts the `owner/repo` path from the stored URL, stripping the scheme,
     * host, leading slash, and any `.git` suffix. Works for GitHub, GitLab
     * (including subgroups), and Bitbucket.
     */
    public function getOwnerRepo(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH) ?? '';
        $path = rtrim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return ltrim($path, '/');
    }

    /**
     * Normalise repo URLs on save: trim whitespace and strip trailing slashes.
     * A trailing "/" survived in the URL otherwise leaks into
     * `https://api.github.com/repos/<owner>/<repo>//pulls` and the GitHub REST
     * API answers HTTP 404.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : rtrim(trim($value), '/'),
        );
    }
}
