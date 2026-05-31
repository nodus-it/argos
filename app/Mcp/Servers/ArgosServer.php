<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ProjectGet;
use App\Mcp\Tools\ProjectList;
use App\Mcp\Tools\TaskConcept;
use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskFeedback;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskImplement;
use App\Mcp\Tools\TaskList;
use App\Mcp\Tools\TaskPr;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Argos')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
    Argos is a web-first dev agent that turns a plan into a pull request through
    three phases: Concept, Implement, Push (PR).

    Typical flow from a planning session:
    1. `project_list` / `project_get` — pick the repository profile to work in.
    2. `task_create` — hand over the plan. It is stored as the task description
       AND as the concept notes, and the Concept phase starts automatically; the
       feature branch is created during that phase.
    3. `task_get` — poll the workflow status. Phases run asynchronously, so write
       tools return immediately; re-read to follow progress.
    4. `task_implement`, then `task_pr` — advance once the previous phase is done.
    5. After `task_pr`, `task_get` exposes the checkout block (repo_url,
       base_branch, feature_branch) so you can `git checkout` the branch locally.
    6. `task_feedback` — send review feedback, which runs the Respond phase.

    A `task` argument accepts either the task ULID or its name.
    TXT)]
class ArgosServer extends Server
{
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ProjectList::class,
        ProjectGet::class,
        TaskList::class,
        TaskGet::class,
        TaskCreate::class,
        TaskFeedback::class,
        TaskConcept::class,
        TaskImplement::class,
        TaskPr::class,
    ];

    /** @var array<int, class-string> */
    protected array $resources = [
        //
    ];

    /** @var array<int, class-string> */
    protected array $prompts = [
        //
    ];
}
