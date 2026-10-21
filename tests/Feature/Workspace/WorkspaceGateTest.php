<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\Demo;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Demo\TraefikRouter;
use App\Services\Workspace\WorkspaceAccessTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class WorkspaceGateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['argos.preview.base_domain' => 'argos.test', 'argos.preview.port' => 80, 'argos.preview.scheme' => 'https']);
        $this->workspace = Workspace::factory()->create(['slug' => 'login']);
        $this->member = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->member->id, 'role' => WorkspaceRole::Member]);
        // Previews are per task: demo-<workspace>-<task>.
        $this->task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'slug' => 'feature']);
        Demo::factory()->create(['task_id' => $this->task->id, 'compose_project' => 'demo-login-feature']);
    }

    private function gate(string $host = 'code-login.argos.test', array $headers = [], array $cookies = [])
    {
        $request = $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Proto' => 'https'] + $headers);
        foreach ($cookies as $name => $value) {
            $request = $request->withUnencryptedCookie($name, $value);
        }

        return $request->get('/_argos/workspace-gate');
    }

    private function tokens(): WorkspaceAccessTokens
    {
        return app(WorkspaceAccessTokens::class);
    }

    public function test_members_pass_on_both_workspace_hosts_others_are_refused(): void
    {
        $cookie = $this->tokens()->cookie($this->workspace, $this->member);
        $this->gate(cookies: ['argos_ws_login' => $cookie])->assertNoContent();
        $this->gate('demo-login-feature.argos.test', cookies: ['argos_ws_login' => $cookie])->assertNoContent();

        $stranger = $this->tokens()->cookie($this->workspace, User::factory()->create());
        $this->gate(headers: ['Accept' => 'application/json'], cookies: ['argos_ws_login' => $stranger])->assertUnauthorized();
    }

    public function test_the_gate_never_starts_a_session(): void
    {
        $response = $this->gate(headers: ['Accept' => 'application/json']);

        $this->assertNull(collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === config('session.cookie')));
    }

    public function test_viewers_get_the_preview_but_not_the_editor(): void
    {
        $viewer = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $cookie = $this->tokens()->cookie($this->workspace, $viewer);

        $this->gate('demo-login-feature.argos.test', cookies: ['argos_ws_login' => $cookie])->assertNoContent();
        $this->gate(headers: ['Accept' => 'application/json'], cookies: ['argos_ws_login' => $cookie])->assertUnauthorized();

        $this->actingAs($viewer)
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'code']))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'preview', 'task' => $this->task->id]))
            ->assertRedirect();
    }

    public function test_unknown_or_foreign_hosts_are_404(): void
    {
        $this->gate('code-nope.argos.test')->assertNotFound();
        $this->gate('code-login.evil.test')->assertNotFound();
        $this->gate('demo-login.argos.test')->assertNotFound();
        // A task without a preview has no host.
        $this->gate('demo-login-other.argos.test')->assertNotFound();
    }

    public function test_a_ticket_is_traded_for_a_host_cookie_and_removed_from_the_url(): void
    {
        $ticket = $this->tokens()->ticket($this->workspace, $this->member);

        $response = $this->gate(headers: ['X-Forwarded-Uri' => '/?folder=/workspace&argos_ticket='.urlencode($ticket)]);

        $response->assertRedirect('https://code-login.argos.test/?folder=%2Fworkspace');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'argos_ws_login');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('none', $cookie->getSameSite());

        Cache::flush();
        $this->gate(cookies: ['argos_ws_login' => $cookie->getValue()])->assertNoContent();
    }

    public function test_revoked_membership_ends_cookie_access(): void
    {
        $cookie = $this->tokens()->cookie($this->workspace, $this->member);
        WorkspaceMember::query()->where('user_id', $this->member->id)->delete();
        Cache::flush();

        $this->gate(headers: ['Accept' => 'application/json'], cookies: ['argos_ws_login' => $cookie])->assertUnauthorized();
    }

    public function test_tickets_and_cookies_are_not_interchangeable_and_bound_to_the_workspace(): void
    {
        $other = Workspace::factory()->create(['slug' => 'other']);
        $ticket = $this->tokens()->ticket($this->workspace, $this->member);

        $this->assertNull($this->tokens()->verifyCookie($this->workspace, $ticket));
        $this->assertNull($this->tokens()->verifyTicket($other, $ticket));
        $this->assertSame($this->member->id, $this->tokens()->verifyTicket($this->workspace, $ticket));
        $this->assertNull($this->tokens()->verifyTicket($this->workspace, $ticket.'x'));

        $this->travel(5)->minutes();
        $this->assertNull($this->tokens()->verifyTicket($this->workspace, $ticket));
    }

    public function test_anonymous_requests_are_refused_or_sent_to_login(): void
    {
        $this->gate(headers: ['Accept' => 'application/json'])->assertUnauthorized();
        $this->gate(headers: ['Accept' => 'text/html', 'X-Forwarded-Uri' => '/?argos_ticket=forged'])->assertRedirect();
        // Never the Argos login inside the embedded editor (Argos in Argos).
        $this->gate(headers: ['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'iframe'])->assertUnauthorized();
    }

    public function test_open_redirects_members_to_the_editor_with_a_fresh_ticket(): void
    {
        $this->actingAs($this->member)
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'code']))
            ->assertRedirectContains('https://code-login.argos.test/?folder=/workspace&argos_ticket=');

        $this->actingAs($this->member)
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'preview', 'task' => $this->task->id]))
            ->assertRedirectContains('https://demo-login-feature.argos.test/?argos_ticket=');

        // A preview needs a task of this workspace.
        $this->actingAs($this->member)
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'preview']))
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('workspaces.open', ['workspace' => $this->workspace, 'target' => 'code']))
            ->assertNotFound();
    }

    public function test_code_routes_are_guarded_by_the_workspace_gate(): void
    {
        $dir = sys_get_temp_dir().'/argos-traefik-'.uniqid();
        config(['argos.preview.traefik_dir' => $dir]);
        $router = app(TraefikRouter::class);

        $url = $router->writeCodeRoute('login', 3000);

        $this->assertSame('https://code-login.argos.test', $url);
        $route = Yaml::parseFile($dir.'/code-login.yml')['http'];
        $this->assertSame('Host(`code-login.argos.test`)', $route['routers']['code-login']['rule']);
        $this->assertSame(['code-login-auth'], $route['routers']['code-login']['middlewares']);
        $this->assertSame('http://argos-ws-login:3000', $route['services']['code-login']['loadBalancer']['servers'][0]['url']);
        $this->assertStringEndsWith('/_argos/workspace-gate', $route['middlewares']['code-login-auth']['forwardAuth']['address']);

        $router->removeCodeRoute('login');
        $this->assertFileDoesNotExist($dir.'/code-login.yml');
        @rmdir($dir);
    }
}
