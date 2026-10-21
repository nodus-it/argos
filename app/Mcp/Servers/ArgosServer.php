<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ProjectGet;
use App\Mcp\Tools\ProjectList;
use App\Mcp\Tools\ThreadMessage;
use App\Mcp\Tools\ThreadPause;
use App\Mcp\Tools\ThreadPlanDecide;
use App\Mcp\Tools\ThreadResume;
use App\Mcp\Tools\WorkspaceCreate;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
use App\Mcp\Tools\WorkspaceShip;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Argos')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
    Argos runs Claude on a repository in workspaces: one branch, one container,
    live preview. An automatic run plans (optionally), implements, fixes failing
    quality gates and opens a pull request — on your own Claude subscription.

    Typical flow from a planning session:
    1. `project_list` / `project_get` — pick the project.
    2. `workspace_create` — hand over what to implement (`prompt`). Choose `plan`
       (off / auto / approve) and `ship` (pr / draft_pr / none), or leave them
       to the project's defaults. It returns immediately.
    3. `workspace_get` — poll the run: thread `auto.state` goes queued → planning
       → implementing → gating/fixing → shipping → done; the pull request appears
       under `pull_request`.
    4. With `plan = approve` the run stops at `awaiting_plan_approval`:
       `thread_plan_decide` approves or rejects (with feedback).
    5. `thread_message` sends feedback into the thread; set `resume_auto` to let
       the run continue on its own. `thread_pause` / `thread_resume` control it.
    6. `workspace_ship` ships the current state on demand.
    TXT)]
class ArgosServer extends Server
{
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ProjectList::class,
        ProjectGet::class,
        WorkspaceList::class,
        WorkspaceGet::class,
        WorkspaceCreate::class,
        WorkspaceShip::class,
        ThreadMessage::class,
        ThreadPlanDecide::class,
        ThreadPause::class,
        ThreadResume::class,
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
