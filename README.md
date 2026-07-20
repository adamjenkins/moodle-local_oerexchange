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
- **Licensing**: Creative Commons via core's `license_manager`.
- **Community**: adaptation-story reviews, reports, and a moderation queue.
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
  launch URLs — no server-side trial execution. See `classes/local/sandbox/`.

## Web services

Custom service `local_oerexchange` (`db/services.php`):

| Function | Auth | Purpose |
|---|---|---|
| `local_oerexchange_search` | site token | Browse/search the catalogue |
| `local_oerexchange_get_resource` | site token | Full detail + structure preview |
| `local_oerexchange_publish_resource` | personal token | Publish a share |
| `local_oerexchange_record_import` | site token | Record a completed import |
| `local_oerexchange_get_config` | site token | Advertised limits |

Two bootstrap steps have no token yet, so they are plain public endpoints
rather than WS functions: `register.php` (site registration) and
`link_consume.php` (exchange a one-time code for the freshly minted personal
token, from the `connect.php` account-linking handshake).

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
