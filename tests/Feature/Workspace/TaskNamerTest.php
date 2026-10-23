<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\Task;
use App\Models\Workspace;
use App\Services\Workspace\TaskNamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskNamerTest extends TestCase
{
    use RefreshDatabase;

    private function namer(): TaskNamer
    {
        return app(TaskNamer::class);
    }

    public function test_branches_carry_the_ticket_key_or_the_argos_prefix(): void
    {
        $workspace = Workspace::factory()->create();

        $this->assertSame('argos/login-seite', $this->namer()->branch($workspace, 'Login-Seite'));
        $this->assertSame('gitlab-42-login-seite', $this->namer()->branch($workspace, 'Login-Seite', 'gitlab-42'));
        $this->assertSame('eng-118-login-seite', $this->namer()->branch($workspace, 'Login-Seite', 'ENG-118'));
        $this->assertSame('argos/groessere-umlaute', $this->namer()->branch($workspace, 'Größere Umlaute'));
    }

    public function test_a_workspace_pattern_and_taken_names(): void
    {
        $workspace = Workspace::factory()->create(['branch_pattern' => 'feature/{ticket}/{title}']);
        Task::factory()->create(['workspace_id' => $workspace->id, 'branch' => 'feature/argos/navbar', 'slug' => 'navbar']);

        $this->assertSame('feature/argos/navbar-2', $this->namer()->branch($workspace, 'Navbar'));
        $this->assertSame('feature/eng-7/navbar', $this->namer()->branch($workspace, 'Navbar', 'ENG-7'));
        $this->assertSame('navbar-2', $this->namer()->slug($workspace, 'Navbar'));
        $this->assertLessThanOrEqual(Task::SLUG_MAX, strlen($this->namer()->slug($workspace, str_repeat('Sehr langer Titel ', 10))));
    }

    public function test_slugs_and_branches_are_per_workspace(): void
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        Task::factory()->create(['workspace_id' => $a->id, 'branch' => 'argos/navbar', 'slug' => 'navbar']);

        $this->assertSame('navbar', $this->namer()->slug($b, 'Navbar'));
        $this->assertSame('argos/navbar', $this->namer()->branch($b, 'Navbar'));
    }
}
