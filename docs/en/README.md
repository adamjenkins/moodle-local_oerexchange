# OER Exchange — User Documentation

`local_oerexchange` is the catalogue and web-service API plugin for the OER
Exchange platform — the central site where open educational resources shared
from teachers' own Moodle sites are published, browsed, previewed, reviewed,
and imported. It is installed on one dedicated "Exchange" site; teachers
interact with it either directly (browsing, reviewing) or through the
[`local_oerclient`](https://github.com/adamjenkins/moodle-local_oerclient)
plugin installed on their own Moodle.

This document is for people *using* the site, not developing it — see the
repository's own source and commit history for implementation details.

## Contents

- [For anyone: browsing and using the catalogue](#for-anyone-browsing-and-using-the-catalogue)
- [For teachers: reviewing and reporting](#for-teachers-reviewing-and-reporting)
- [For site administrators](#for-site-administrators)
  - [Initial setup](#initial-setup)
  - [Approving a client site](#approving-a-client-site)
  - [Managing the sandbox plugin allowlist](#managing-the-sandbox-plugin-allowlist)
  - [Moderation](#moderation)
- [Privacy and data handling](#privacy-and-data-handling)
- [Troubleshooting](#troubleshooting)

## For anyone: browsing and using the catalogue

The catalogue at `/local/oerexchange/index.php` is open to anonymous
visitors — no login is required to search or browse.

- **Search and filter** by keyword, resource type (whole course or single
  activity), license, and language.
- Click any card to open the **resource page**, which shows:
  - A **structure preview** — the course's sections and activities — built
    from the shared backup without anyone needing to import it first.
  - The **license** it was shared under (Creative Commons or public domain).
  - **Required plugins**, if the resource uses any activity type or course
    format that isn't part of standard Moodle. Each is marked either
    "included in trial" (you can try it without installing anything) or
    "not in trial — won't appear when you try it" (you'd need to install
    that plugin on your own site before importing, or accept that it will
    be skipped).
  - **Adaptation-story reviews** from other teachers who have used the
    resource — what they changed and how it went.
  - A **download count** and **import count**, and an attribution link if
    the resource is itself adapted from another one on the Exchange.
- **Try it** opens the resource in a full, disposable Moodle running
  entirely in your browser (no account, no server-side execution, nothing
  saved anywhere) — the fastest way to see whether a resource fits your
  course before committing to an import. It only appears when the site
  administrator has enabled the sandbox (see below) and the resource has
  finished processing.
- **Download** fetches the raw backup file directly, if you'd rather import
  it by hand through Moodle's own course restore screen.

## For teachers: reviewing and reporting

Reviewing and reporting require being logged in and linked to a personal
Exchange account (this normally happens automatically the first time you
share or try to act from the `local_oerclient` plugin on your own site — see
its documentation for the "Link my Exchange account" step).

- **Share your adaptation story**: on any resource page, fill in how you
  used it, what you changed, and how it went. This is the platform's core
  idea — sharing isn't just uploading a file, it's helping the next teacher
  adapt it too. An optional star rating is available.
- **Report**: flag a resource for copyright concerns, quality issues, spam,
  or another reason. Reports go to the moderation queue; you won't see a
  resource disappear immediately, but it will be reviewed.

## For site administrators

### Initial setup

After installing the plugin (`Site administration ▸ Notifications`, then
follow the upgrade prompt), open **Site administration ▸ Plugins ▸ Local
plugins ▸ OER Exchange** to configure:

- **Enable sandbox (Try it)** — turns the "Try it" button on across the
  catalogue. Requires a Moodle Playground deployment to point at (see
  below).
- **Sandbox base URL** — the same-origin path where the Moodle Playground
  static bundle is deployed, e.g. `https://your-exchange.example/try/`.
  Setting up that deployment is a server-administration task, not covered
  here — see the platform's `oer-sandbox` deployment kit documentation.

Capabilities `local/oerexchange:moderate` and `local/oerexchange:managesites`
control who can moderate reports/failed parses and who can approve client
sites / curate the plugin allowlist — both are granted to the **Manager**
role by default.

### Approving a client site

When a Moodle site registers itself against your Exchange (via its own
`local_oerclient` plugin's "Register with the Exchange" page), it appears
under **Site administration ▸ Plugins ▸ Local plugins ▸ OER Exchange ▸
Registered sites**, listed as *pending*.

1. Review the site's name, URL, and contact email.
2. Click **Approve**. This creates a dedicated, non-interactive account on
   the Exchange for that site and mints a web service token for it — the
   site's actual "key" is this token, not a separate password.
3. The token is emailed to the contact address (if outbound email is
   configured on this server) and shown once on the approval screen.
   **Copy it immediately if you need to relay it another way** — it is
   never stored in a recoverable form and won't be shown again.
4. The client site administrator pastes the token into their own
   `local_oerclient` settings ("Site token") to complete the connection.

**Revoke** immediately and permanently disables a site's access — every
token it holds stops working right away. Re-approving a previously revoked
site reuses the same underlying account and issues a fresh token.

### Managing the sandbox plugin allowlist

Some shared resources use contrib activity modules or course formats that
aren't part of standard Moodle. The sandbox ("Try it") can only include
plugins your site administrator has explicitly curated and uploaded — this
is a deliberate safety boundary: resource metadata alone can never cause a
visitor's browser to install and run arbitrary code.

Under **Site administration ▸ Plugins ▸ Local plugins ▸ OER Exchange ▸
Sandbox plugin allowlist**:

1. Enter the plugin type (e.g. `mod`), plugin name (frankenstyle without the
   type prefix, e.g. `board`), and the Moodle branch it's for
   (e.g. `5.2`) — this must match one of the branches your sandbox
   deployment actually offers.
2. Upload the plugin's release ZIP (from its own GitHub releases, or a
   community registry such as camp-registry.org).
3. Once added, any resource whose required-plugins list matches an active
   allowlist entry for the trial's branch will have that plugin
   automatically installed when someone clicks Try it. Entries can be
   disabled without deleting them.

### Moderation

**Site administration ▸ Plugins ▸ Local plugins ▸ OER Exchange ▸ Moderation
queue** (requires `local/oerexchange:moderate`) shows:

- **Open reports** — resolve (mark handled) or dismiss (no action needed);
  **Hide** takes the resource off the public catalogue immediately without
  deleting it.
- **Failed parses** — resources whose uploaded backup could not be
  processed (structure preview unavailable, "Try it" unavailable). The
  error message is shown to help you decide whether to contact the sharing
  teacher.

## Privacy and data handling

The Exchange holds personal data for: reviews you write, reports you file,
resources you've shared (attributed to your account), a record of imports
and sandbox trials, and — briefly, during the account-linking process — a
one-time code. All of it is covered by the plugin's GDPR privacy provider:
use Moodle's own **Site administration ▸ Users ▸ Privacy and policies ▸
Data requests** flow to export or delete your data. Shared resources
themselves are not deleted on request (other sites may already have
imported them) — instead, your personal attribution to them is removed.

Every backup uploaded for sharing is independently verified server-side to
contain no user data before it is published, regardless of what the
uploading site claims.

## Troubleshooting

- **"Try it" doesn't appear on a resource** — either the sandbox isn't
  enabled site-wide (administrator setting), or that resource's backup
  hasn't finished parsing yet (check the moderation queue for a failed
  parse).
- **A required plugin shows "not in trial"** — the site administrator
  hasn't added it to the sandbox plugin allowlist for the resource's Moodle
  version. Contact them if you'd like it added, or install the plugin on
  your own site before importing instead.
- **Download link is missing** — the resource hasn't finished processing
  yet, or was hidden/removed by moderation.
