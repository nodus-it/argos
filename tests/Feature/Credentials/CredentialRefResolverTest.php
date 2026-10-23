<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\TaskProviderKind;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Credentials\CredentialRefResolver;
use App\Services\IssueTracker\TaskProviderBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Credential picker values (`oauth:` / `pat:`) never reach somebody else's account. */
class CredentialRefResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ref_resolves_only_the_pickers_own_account(): void
    {
        $ada = User::factory()->create();
        $bob = User::factory()->create();
        $bobs = ConnectedAccount::factory()->create(['user_id' => $bob->id, 'provider' => 'github']);
        $adas = ConnectedAccount::factory()->create(['user_id' => $ada->id, 'provider' => 'github', 'name' => 'Ada']);
        $refs = app(CredentialRefResolver::class);

        $this->assertNull($refs->account($ada, 'oauth:'.$bobs->id));
        $this->assertTrue($refs->account($ada, 'oauth:'.$adas->id)?->is($adas));
        $this->assertNull($refs->account($ada, 'oauth:'.$adas->id.' or 1=1'));
        $this->assertSame(['oauth:'.$adas->id => 'Ada'], $refs->options($ada, ['github'])['oauth']);
    }

    public function test_the_columns_round_trip(): void
    {
        $credential = ProviderCredential::factory()->create();

        $this->assertSame(['auth_method' => 'pat', 'connected_account_id' => null, 'provider_credential_id' => $credential->id], CredentialRefResolver::toColumns('pat:'.$credential->id));
        $this->assertSame(['auth_method' => 'oauth', 'connected_account_id' => 7, 'provider_credential_id' => null], CredentialRefResolver::toColumns('oauth:7'));
        $this->assertSame('oauth:7', CredentialRefResolver::fromColumns(7, null));
        $this->assertSame('pat:'.$credential->id, CredentialRefResolver::fromColumns(null, $credential->id));
    }

    public function test_a_tracker_cannot_be_bound_with_another_persons_account(): void
    {
        $workspace = Workspace::factory()->create();
        $ada = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $ada->id, 'role' => WorkspaceRole::Admin]);
        $bobs = ConnectedAccount::factory()->create(['user_id' => User::factory()->create()->id, 'provider' => 'github']);
        $data = ['kind' => TaskProviderKind::GitHub->value, 'mode' => 'poll', 'external_project_ref' => 'acme/widget', 'credential_ref' => 'oauth:'.$bobs->id];

        try {
            app(TaskProviderBindingService::class)->createFor($workspace, $ada, $data);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credential_ref', $e->errors());
        }

        $adas = ConnectedAccount::factory()->create(['user_id' => $ada->id, 'provider' => 'github']);
        $binding = app(TaskProviderBindingService::class)->createFor($workspace, $ada, [...$data, 'credential_ref' => 'oauth:'.$adas->id]);
        $this->assertSame($adas->id, $binding->connected_account_id);
        $this->assertSame('oauth', $binding->auth_method->value);
    }
}
