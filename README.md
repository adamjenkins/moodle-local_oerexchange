# local_oerexchange

The central catalogue and API plugin for the **OER Exchange** platform — an
open-educational-resources sharing platform built on Moodle. Runs on the
dedicated Exchange site; teachers share and import through the companion
[`local_oerclient`](https://github.com/adamjenkins/moodle-local_oerclient)
plugin installed on their own Moodle sites.

## What it does

- **Catalogue**: publish, browse, search, structure-preview courses/activities
  shared from client sites, before anyone imports them.
- **Identity**: site registration (with admin approval) for client sites, plus
  a personal account-linking handshake so shares/reviews are attributed to a
  real Exchange account.
- **Licensing**: Creative Commons via core's `license_manager`. An admin
  chooses which licences sharing may use (Allowed licences setting; the CC
  set by default) — enforced on every publish path and advertised to client
  sites, with the licence dropdown preselecting the sharer's remembered
  choice, the site default licence, or CC BY-SA, in that order.
- **Community**: adaptation-story reviews, reports, and a moderation queue.
  A moderator's takedown is a state of its own that the author cannot lift
  **or delete** — while a resource is held, its author and co-authors keep
  every other control but cannot remove it, so a complaint and the record of
  the takedown survive the person they are about. The queue can restore it.
- **No user data, and the author is told**: every uploaded backup is checked
  server-side for user data before it can be published — the backup's own
  recorded setting *and* an independent inspection of its `users.xml`, so a
  client that lies is caught too. A backup carrying user data is refused, the
  uploaded file is **deleted** rather than retained, and the reason is shown
  to the author on their own resource page and reported to their client site.
  A merely corrupt backup is kept for a moderator to diagnose, and authors see
  a generic message for it — internal error text can name server paths.
- **Author control**: authors hide, show, delete, and replace the file of
  their own resources, and may decline sandbox availability with a reason.
  The Exchange serves exactly one version per resource — an update supersedes
  the previous one, keeping the catalogue entry, its link and its reviews.
- **Co-authors**: an author can name further authors on anything they have
  shared, by username or email address, from the resource page. A co-author
  gets **the same rights as the creator** — replacing the file, the
  thumbnail, hiding, deleting, the Try-it opt-out, freshness confirmation,
  and adding or removing co-authors themselves. The creator is not a
  co-author record, so nobody they add can remove them. Everyone added is
  notified and is credited publicly on the resource page. See
  `classes/local/coauthor_manager.php`.
- **Sharing affordances**: share buttons on resource and profile pages, with
  admin-configurable destinations, each showing its network's logo from
  Moodle's own bundled FontAwesome (nothing is bundled into the plugin).
  Every target is a plain link — no third-party script is loaded onto a
  catalogue page.
- **Abandoned courseware** (off by default): a nightly check warns the author
  of any published resource unmaintained past a configurable threshold
  (default 2 years). The author can update it or press a one-click
  "Still fresh" button; if neither happens within a configurable grace period
  (default 60 days), the resource is removed from the catalogue — into the
  moderation queue's restorable list, never irreversibly. Resources whose
  author is unreachable are left for human moderators.
- **Educator profiles**: a shareable profile page per educator
  (`/local_oerexchange/u/{slug}`, bio/expertise/badges/portfolio links/metrics/
  resource grid), auto-created on first published resource. Author attribution
  and a creator/moderator-editable thumbnail on every resource, Open Graph
  social-preview tags via the Hooks API, a nightly badge-computation task, and
  a full-deletion-with-tombstone GDPR path (a departing user's shared
  courseware is genuinely deleted, not just anonymized, while existing links
  degrade gracefully instead of breaking). See
  `classes/local/profile_manager.php`, `classes/local/badge_manager.php`, and
  `classes/route/controller/`.
- **Sandbox integration**: builds Moodle Playground (in-browser, WASM) trial
  launch URLs — no server-side trial execution. A trial boots in the language
  the visitor is reading the catalogue in, where that is not English. See
  `classes/local/sandbox/`.

## Web services

Custom service `local_oerexchange` (`db/services.php`):

| Function | Auth | Purpose |
|---|---|---|
| `local_oerexchange_search` | site token | Browse/search the catalogue |
| `local_oerexchange_get_resource` | site token | Full detail + structure preview |
| `local_oerexchange_publish_resource` | personal token | Publish a share |
| `local_oerexchange_record_import` | site token | Record a completed import |
| `local_oerexchange_get_share_status` | personal token | Live state of one's own published resource |
| `local_oerexchange_get_config` | site token | Advertised limits |

Two bootstrap steps have no token yet, so they are plain public endpoints
rather than WS functions: `register.php` (site registration) and
`link_consume.php` (exchange a one-time code for the freshly minted personal
token, from the `connect.php` account-linking handshake).

Because `register.php` cannot authenticate its caller, it is bounded two
ways: registering a URL that already has a pending row returns that row
instead of adding another (so a client retrying is harmless), and new
registrations site-wide are capped per hour, answering `429` beyond that.

## Anonymous access

Browsing the catalogue and viewing a resource page work without logging in.
Downloading a resource's `.mbz` does not, unless **Allow anonymous download**
is turned on — and that setting governs **Try it** as well, because a sandbox
trial downloads the `.mbz` itself to boot. With the setting off, an anonymous
visitor who clicks Try it is sent to the login page.

A "site key" issued on approval **is a real core web service token**, minted
against a dedicated, non-interactive Moodle account created for that site
(`local_oerexchange\local\site_manager`) — not a custom auth scheme.

## Requirements

- Moodle 5.0–5.2 (`$plugin->supported`).
- PHP as required by the target Moodle version.

## Installation

```bash
git clone https://github.com/adamjenkins/moodle-local_oerexchange.git local/oerexchange
php admin/cli/upgrade.php
```

## Acknowledgments

This plugin's sandbox integration (`classes/local/sandbox/`) builds launch
URLs for **[Moodle Playground](https://github.com/ateeducacion/moodle-playground)**
— all of the actual work of running Moodle in a browser (WASM boot,
blueprint provisioning, service-worker/bundle machinery) is that project's,
not ours; this plugin only constructs a URL for it. Moodle Playground itself
runs on **[WordPress Playground](https://github.com/WordPress/wordpress-playground)**'s
`@php-wasm/web` PHP-in-WebAssembly runtime, the foundational piece that makes
any of this possible in a browser tab. Deployment of the actual sandbox
(building and serving the static bundles) lives in the companion `oer-sandbox`
repo, whose README carries the fuller acknowledgment.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
