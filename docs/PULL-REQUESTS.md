# Pull Requests

Every [task](TASKS.md) ends in its own pull request (GitLab: merge request)
against its base branch. Once **Commit & PR** has opened it, the task's
sidebar follows it: state, checks, reviews and conflicts — with actions that
hand the work back to Claude. Merging the pull request finishes the task.

## Contents

- [Where it shows](#where-it-shows)
- [State](#state)
- [Checks and pipelines](#checks-and-pipelines)
- [Reviews and review comments](#reviews-and-review-comments)
- [Conflicts and a moved base branch](#conflicts-and-a-moved-base-branch)
- [Auto-merge](#auto-merge)
- [Done and cancelled](#done-and-cancelled)
- [Sync](#sync)
- [Provider support](#provider-support)

## Where it shows

- The **Pull request** section of the task's sidebar: number and state,
  checks, reviews, conflict warnings and the actions below; **Refresh** reads
  it again right away.
- The task header, the [task switcher](TASKS.md#the-task-switcher) and
  [My work](WORKSPACES.md#the-w-area) show the pull request and its state.
- Changes are announced in the chat (*The pipeline failed*, *Changes were
  requested in the review*, *The pull request was merged — the task is done*,
  …) and as notifications of kind *Someone shipped a task* to the task's owner
  and collaborators.

The actions need the right to ship in the task (owner, collaborators,
workspace admins). Handing something to Claude posts a message in the task
under your name; it runs on the owner's subscription like every message.

## State

The state is *draft*, *open*, *merged* or *closed*. A draft offers **Ready for
review**, which takes it out of draft state on the provider (GitLab removes the
`Draft:` prefix from the title).

## Checks and pipelines

The **Checks** row shows the combined state of the pull request's checks or
pipeline — *running*, *green*, *failed* or *none* — with a link to the run.

When the checks failed, **Let Claude fix it** collects the failure output
from the provider and posts it into the task with the request to fix it:

| Provider | What Claude gets |
|---|---|
| GitHub | Failed check runs (up to ten) with their output and annotations, the log tail of failed GitHub Actions jobs, and failed commit statuses |
| GitLab | The job traces of the failed jobs of the head pipeline (up to five) |
| Bitbucket | Name, description and link of each failed or stopped build status — Bitbucket offers no logs through this API |

## Reviews and review comments

The **Reviews** row shows the overall review state: *approved*, *changes
requested*, *commented*, or *No reviews yet*. Below it, *N review comments*
unfolds what reviewers wrote: reviews with text, line comments (the file and
line open the review mode) and general comments, each linking to the
provider. Argos' own pull request comments (pushed commits) are left out.

**Collect in my review** takes the comments over into your own review, so
they reach Claude together with your own line comments as one message:

- Line comments become drafts at their line, marked *(name on the pull
  request)*. In the review mode they sit in the diff like your own comments —
  edit or discard them before sending.
- General comments (no line) go into the message text in the composer.
- A comment is taken over once; the sidebar marks it *In your review*.

In the review mode each pull request comment also has **Add to my review**
on its own. Comments on lines the diff does not show are listed above the
file.

## Conflicts and a moved base branch

- *Conflicts with `<base>` — the branch needs a rebase.* when the provider
  reports merge conflicts.
- *`<base>` moved on since this branch started.* when the base branch has new
  commits (GitHub and GitLab; Bitbucket does not report it).

Both offer **Rebase by Claude**: Claude runs `git fetch`, rebases the branch
onto `origin/<base>`, resolves the conflicts and tells you when it is done.
Ship again afterwards to push the rebased branch.

## Auto-merge

**Merge automatically** lets the provider merge the pull request on its own
once the pipeline is green and the reviews allow it. It uses the provider's
**native** auto-merge; Argos does not merge itself.

- Switch it on in the sidebar, in the **New task** form, through the REST API
  (`auto_merge` when starting a task) or MCP (`task_create` with
  `auto_merge`). Chosen before the pull request exists, it is applied as soon
  as shipping opens it.
- If the provider refuses, the toggle goes back off and the task says
  *Auto-merge could not be switched on for this repository*.

| Provider | Behaviour |
|---|---|
| GitHub | Uses GitHub's auto-merge. The repository must allow it (*Settings → General → Allow auto-merge*); GitHub also refuses when the pull request could be merged right away. Uses the repository's allowed merge method. |
| GitLab | Uses *Merge when pipeline succeeds*. Argos only arms it while a pipeline of the merge request is running — without a running pipeline it is refused (so the merge request is not merged on the spot). |
| Bitbucket | Not supported — switching it on always fails with the hint. Merge in Bitbucket. |

## Done and cancelled

- **Merged** → the task is **Done**: it takes no more messages; a linked
  ticket is closed where the integration says so (see
  [Tickets](TICKETS.md#closing-on-merge)). A merged
  [setup task](PREPARE-PROJECT.md#the-setup-task) makes the default branch the
  source of the workspace setup.
- **Closed without merge** → the task is **Cancelled**.

Worktree, database and preview of finished tasks are removed after the
workspace's cleanup period; branch, pull request and conversation stay (see
[Tasks → Cleanup](TASKS.md#cleanup)). To continue, start a
[follow-up task](TASKS.md#finishing-a-task).

## Sync

Argos re-reads the pull requests of open tasks every
`ARGOS_WORKSPACE_PR_SYNC_MINUTES` (default **3**, `argos:tasks:sync-prs`, run
by the scheduler), when you open the pull request section and after each of
its actions. Review details are cached for a minute. A provider error keeps
the last known state.

## Provider support

| | GitHub | GitLab | Bitbucket |
|---|---|---|---|
| State, draft, ready for review | yes | yes | yes (ready for review only if Bitbucket accepts it through the API) |
| Checks with link | check runs + commit statuses | head pipeline | build statuses |
| Failed-check log for Claude | yes | yes | status descriptions only |
| Reviews and review comments | yes | approvals + discussions | approvals + comments |
| Conflicts | yes | yes | yes |
| Base branch moved on | yes | yes | no (Bitbucket does not report it) |
| Auto-merge | yes (if allowed in the repository) | only while a pipeline runs | no |
