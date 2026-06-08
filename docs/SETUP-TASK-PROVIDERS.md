# Task Provider integration (issue trackers)

Argos can import issues from external systems (GitHub, GitLab, Linear) as tasks
and automatically leave a comment on the issue when a phase finishes.

## Prerequisites

- A configured **Connected Account** (OAuth) for GitHub, GitLab or Linear
  (see `docs/SETUP-GITHUB.md`, `docs/SETUP-GITLAB.md` or `docs/SETUP-LINEAR.md`)
- The project (Repo Profile) must already exist in Argos

## Creating a binding

1. In the admin panel open the project under **Repo Profiles**
2. Select the **Task Provider Bindings** tab → **New Binding**
3. Fill in the fields:

| Field | Description |
|---|---|
| **Kind** | GitHub, GitLab or Linear |
| **Mode** | `Poll` (periodic fetch every 5 min) or `Webhook` (push events) |
| **Connected Account** | The OAuth account with API access |
| **External Project Ref** | GitHub/GitLab: `owner/repo` (e.g. `acme/widget`); Linear: team key (e.g. `ENG`) |
| **Labels** | Optional: only issues carrying these labels are imported |

4. Save → the binding is in status **Pending**
5. Run the **Setup** action → the binding is activated (status **Active**)

## Webhook mode (recommended for real time)

When you run Setup in webhook mode, Argos generates a webhook secret and prints
the webhook URL. Register that URL in the external system:

**GitHub:** Repository → Settings → Webhooks → Add webhook
- Payload URL: `https://<ARGOS_URL>/webhooks/issues/github/<binding-id>`
- Content type: `application/json`
- Secret: (the generated secret)
- Events: **Issues**

**GitLab:** Project → Settings → Webhooks
- URL: `https://<ARGOS_URL>/webhooks/issues/gitlab/<binding-id>`
- Secret token: (the generated secret)
- Trigger: **Issues events**

**Linear:** Registered automatically during Setup — no manual step in the Linear
interface required (the OAuth scope `admin` is a prerequisite).
- Webhook URL: `https://<ARGOS_URL>/webhooks/issues/linear/<binding-id>`
- Signature: HMAC-SHA256 in the `Linear-Signature` header

## Poll mode

In poll mode Argos actively fetches new issues every 5 minutes. No webhook in
the external system is required, and `APP_URL` does not need to be publicly
reachable.

## Filtering

The **Labels** field filters issues: only issues carrying at least one of the
given labels are imported as tasks. Without a filter, all open issues are
imported.

## Environment variables

No additional variables are required. `APP_URL` is used as the base of the
webhook URL and must be publicly reachable (webhook mode only).

```env
# Base URL — must be publicly reachable when webhook mode is used
APP_URL=https://argos.example.com
```

## Comment-back

After each phase finishes, Argos automatically posts a comment on the linked
issue (the comment text is currently posted in German):

```
**Argos** — Phase **implement** abgeschlossen mit Status: **success**
```

If commenting fails (e.g. because of an expired token), the error is logged but
the workflow is not interrupted.
