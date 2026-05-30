<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TaskProviderKind;
use App\Http\Controllers\Webhooks\IssueWebhookController;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Signature('argos:webhook:simulate
    {binding : ID of the TaskProviderBinding to deliver the fake issue to}
    {--title= : Issue title (default: auto-generated)}
    {--body= : Issue body / description}
    {--state=open : Issue state (open/closed)}
    {--label=* : Label name(s) to attach; repeat the flag for several}
    {--id= : External issue id (default: random)}
    {--number= : Issue number/iid (default: random)}')]
#[Description('Deliver a signed, fake provider issue webhook to a binding locally — no public URL, tunnel, or external account needed. Runs the real controller (signature check, idempotency, job dispatch) so you can watch a Task appear.')]
final class SimulateIssueWebhookCommand extends Command
{
    public function __construct(private readonly IssueWebhookController $controller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $binding = TaskProviderBinding::find((string) $this->argument('binding'));
        if ($binding === null) {
            $this->error("No TaskProviderBinding with id={$this->argument('binding')}.");

            return self::FAILURE;
        }

        $kind = $binding->kind;
        if (! $kind instanceof TaskProviderKind) {
            $this->error('Binding has no usable provider kind.');

            return self::FAILURE;
        }

        // A webhook binding carries an encrypted secret; a poll binding may not.
        // Generate and persist one on the fly so the simulator is self-sufficient.
        if ($binding->webhook_secret === null || $binding->webhook_secret === '') {
            $binding->webhook_secret = bin2hex(random_bytes(20));
            $binding->save();
            $this->warn('Binding had no webhook_secret — generated and saved one for the simulation.');
        }

        $issueId = (string) ($this->option('id') ?? '') ?: (string) random_int(100000, 999999);
        $number = (int) ($this->option('number') ?? 0) ?: random_int(1, 9999);
        $title = (string) ($this->option('title') ?? '') ?: "Simulated issue #{$number}";
        $body = (string) ($this->option('body') ?? '') ?: 'Created by argos:webhook:simulate';
        $state = (string) $this->option('state');
        /** @var list<string> $labels */
        $labels = array_values(array_filter((array) $this->option('label'), 'is_string'));

        [$envelope, $eventType] = $this->buildEnvelope($kind, $issueId, $number, $title, $body, $state, $labels);
        $rawBody = (string) json_encode($envelope);

        $request = $this->buildRequest($kind, $binding, $rawBody, $eventType);

        $countBefore = ExternalIssueLink::where('task_provider_binding_id', $binding->id)->count();

        $this->line("Delivering <info>{$kind->value}</info> issue webhook to binding <info>{$binding->id}</info> (labels: ".(implode(', ', $labels) ?: '—').')…');

        $response = $this->controller->handle($request, $kind->value, (string) $binding->id);

        $this->line("Controller responded: HTTP <info>{$response->getStatusCode()}</info> ".trim((string) $response->getContent()));

        if ($response->getStatusCode() !== 200) {
            $this->error('Webhook was rejected (signature/idempotency/secret). See the argos log channel.');

            return self::FAILURE;
        }

        // ProcessIncomingIssueJob runs inline on the sync queue, otherwise the
        // worker picks it up — poll briefly so the command shows the result.
        $link = $this->awaitIngestedLink($binding, $issueId, $countBefore);

        if ($link === null) {
            $this->warn('Webhook accepted, but no ExternalIssueLink appeared yet.');
            $this->line('If your queue is async, the worker may still be processing — check the Tasks list in a moment.');

            return self::SUCCESS;
        }

        if ($link->task_id === null) {
            $this->info("Issue ingested, but filtered out (no Task created) — labels did not pass the binding filter. Link #{$link->id}.");

            return self::SUCCESS;
        }

        $this->info("Task #{$link->task_id} created from the simulated issue (link #{$link->id}). Open the Tasks list to see it.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $labels
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function buildEnvelope(
        TaskProviderKind $kind,
        string $issueId,
        int $number,
        string $title,
        string $body,
        string $state,
        array $labels,
    ): array {
        return match ($kind) {
            TaskProviderKind::GitHub => [[
                'action' => 'opened',
                'issue' => [
                    'id' => $issueId,
                    'number' => $number,
                    'title' => $title,
                    'body' => $body,
                    'state' => $state,
                    'html_url' => "https://github.com/simulated/repo/issues/{$number}",
                    'labels' => array_map(fn (string $l): array => ['name' => $l], $labels),
                ],
                'repository' => ['full_name' => 'simulated/repo'],
                'sender' => ['login' => 'argos-simulate'],
            ], 'issues'],

            TaskProviderKind::GitLab => [[
                'object_kind' => 'issue',
                'object_attributes' => [
                    'id' => $issueId,
                    'iid' => $number,
                    'title' => $title,
                    'description' => $body,
                    'state' => $state,
                    'url' => "https://gitlab.com/simulated/repo/-/issues/{$number}",
                ],
                'labels' => array_map(fn (string $l): array => ['title' => $l], $labels),
            ], null],

            TaskProviderKind::Linear => [[
                'type' => 'Issue',
                'action' => 'create',
                'data' => [
                    'id' => $issueId,
                    'url' => "https://linear.app/simulated/issue/{$number}",
                    'title' => $title,
                    'description' => $body,
                    'state' => ['name' => $state],
                    'labels' => array_map(fn (string $l): array => ['name' => $l], $labels),
                ],
            ], null],
        };
    }

    private function buildRequest(
        TaskProviderKind $kind,
        TaskProviderBinding $binding,
        string $rawBody,
        ?string $eventType,
    ): Request {
        $secret = (string) $binding->webhook_secret;
        $delivery = (string) Str::uuid();

        $server = ['CONTENT_TYPE' => 'application/json'];

        switch ($kind) {
            case TaskProviderKind::GitHub:
                $server['HTTP_X_GITHUB_EVENT'] = $eventType ?? 'issues';
                $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
                $server['HTTP_X_GITHUB_DELIVERY'] = $delivery;
                break;
            case TaskProviderKind::GitLab:
                $server['HTTP_X_GITLAB_TOKEN'] = $secret;
                $server['HTTP_X_GITLAB_EVENT_UUID'] = $delivery;
                break;
            case TaskProviderKind::Linear:
                $server['HTTP_LINEAR_SIGNATURE'] = hash_hmac('sha256', $rawBody, $secret);
                $server['HTTP_LINEAR_DELIVERY'] = $delivery;
                break;
        }

        return Request::create(
            "/webhooks/issues/{$kind->value}/{$binding->id}",
            'POST',
            [],
            [],
            [],
            $server,
            $rawBody,
        );
    }

    private function awaitIngestedLink(TaskProviderBinding $binding, string $issueId, int $countBefore): ?ExternalIssueLink
    {
        // Up to ~3s, so an async worker has a chance to catch up.
        for ($i = 0; $i < 15; $i++) {
            $link = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
                ->where('external_id', $issueId)
                ->first();

            if ($link !== null) {
                return $link;
            }

            $currentCount = ExternalIssueLink::where('task_provider_binding_id', $binding->id)->count();
            if ($currentCount > $countBefore) {
                return ExternalIssueLink::where('task_provider_binding_id', $binding->id)->latest('id')->first();
            }

            usleep(200_000);
        }

        return null;
    }
}
