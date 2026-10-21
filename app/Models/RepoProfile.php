<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\BackingService;
use App\Enums\GitProvider;
use App\Enums\WorkerSource;
use App\Enums\WorkspaceStatus;
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
 * @property array<int, array{host: string, username?: string|null, token: string}>|null $composer_registries
 * @property array<int, array{name: string, value: string}>|null $worker_env
 * @property array<int, string>|null $worker_services
 * @property array<string, array<string, string>>|null $worker_service_config
 * @property bool $live_demo_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConnectedAccount|null $connectedAccount
 * @property-read WorkerStack|null $workerStack
 * @property-read Collection<int, TaskProviderBinding> $taskProviderBindings
 * @property array<string, mixed>|null $auto_settings
 * @property int|null $automation_user_id
 * @property-read User|null $automationUser
 */
class RepoProfile extends Model implements HasApiTokensContract
{
    // Project-bound Sanctum API tokens: one token = one project, fine-scoped
    // (e.g. CI). $repoProfile->createToken('ci', ['workspaces:write']).
    use HasApiTokens;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'automation_user_id',
        'auto_settings',
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
        'composer_registries',
        'worker_env',
        'worker_services',
        'worker_service_config',
        'live_demo_enabled',
    ];

    protected function casts(): array
    {
        return [
            'auto_settings' => 'array',
            'token' => 'encrypted',
            'platform' => GitProvider::class,
            'auth_method' => AuthMethod::class,
            'worker_source' => WorkerSource::class,
            'worker_agent_name' => AgentName::class,
            'composer_registries' => 'encrypted:array',
            'worker_env' => 'encrypted:array',
            'worker_services' => 'array',
            'worker_service_config' => 'array',
            'live_demo_enabled' => 'boolean',
        ];
    }

    /** The person auto-threads of project tokens and issue triggers act for. */
    public function automationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'automation_user_id');
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * Workspaces that are not stopped or failed: the API's "open_workspaces".
     *
     * @return HasMany<Workspace, $this>
     */
    public function openWorkspaces(): HasMany
    {
        return $this->workspaces()->whereNotIn('status', [WorkspaceStatus::Stopped, WorkspaceStatus::Failed]);
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
     * `getOwnerRepo()` split into owner and repository name. The repository
     * is the last segment; GitLab owners keep their subgroups.
     *
     * @return array{0: string, 1: string}
     */
    public function ownerAndRepo(): array
    {
        $parts = explode('/', $this->getOwnerRepo());
        $repo = (string) array_pop($parts);

        return [implode('/', $parts), $repo];
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
