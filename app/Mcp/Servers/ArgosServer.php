<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskMessage;
use App\Mcp\Tools\TaskPause;
use App\Mcp\Tools\TaskPlanDecide;
use App\Mcp\Tools\TaskResume;
use App\Mcp\Tools\TaskShip;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Argos')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
    Argos runs Claude on repositories. A workspace is a project (one repository,
    one container); every task is one conversation with its own branch, preview
    and pull request. An automatic task plans (optionally), implements, fixes
    failing quality gates and opens a pull request — on your own Claude
    subscription.

    Typical flow from a planning session:
    1. `workspace_list` / `workspace_get` — pick the workspace.
    2. `task_create` — hand over what to implement (`prompt`), optionally with a
       ticket (`issue`). Choose `plan` (off / auto / approve) and `ship`
       (pr / draft_pr / none), or leave them to the workspace's defaults. It
       returns immediately.
    3. `task_get` — poll the run: `auto.state` goes queued → planning →
       implementing → gating/fixing → shipping → done; the pull request appears
       under `pull_request`.
    4. With `plan = approve` the run stops at `awaiting_plan_approval`:
       `task_plan_decide` approves or rejects (with feedback).
    5. `task_message` sends feedback into the task; set `resume_auto` to let
       the run continue on its own. `task_pause` / `task_resume` control it.
    6. `task_ship` ships the current state on demand.
    TXT)]
class ArgosServer extends Server
{
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        WorkspaceList::class,
        WorkspaceGet::class,
        TaskCreate::class,
        TaskGet::class,
        TaskShip::class,
        TaskMessage::class,
        TaskPlanDecide::class,
        TaskPause::class,
        TaskResume::class,
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
