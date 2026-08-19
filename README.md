# local_oerexchange

The central catalogue and API plugin for the **OER Exchange** platform — an
open-educational-resources sharing platform built on Moodle. Runs on the
dedicated Exchange site; teachers share and import through the companion
[`local_oerclient`](https://github.com/adamjenkins/moodle-local_oerclient)
plugin installed on their own Moodle sites.

## What it does

- **Catalogue**: publish, browse, search, structure-preview courses/activities
  shared from client sites, before anyone imports them.
- **Required-plugins disclosure**: every uploaded backup is scanned for
  non-standard dependencies — activity modules and course format from the
  manifest, plus question types, question behaviours, activity subplugins,
  advanced-grading methods and blocks read out of the archive itself — and
  the resource page, sandbox trial and client preview all disclose them
  (with per-plugin trial availability). A `Re-scan stored backups for
  required plugins` scheduled task (disabled by default; run it on demand)
  re-applies improved detection to the whole existing catalogue.
- **Events**: `resource_shared` and `resource_updated` are regular Events
  API events, so core Event monitoring rules (or any observer) can notify
  on new shares and updates.
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
- **A standing list of what is held**: a moderator-only report
  (`moderate_hidden.php`, linked from the moderation-queue block with a count)
  lists every resource a moderator has taken down, recording when and by whom,
  flagging whether the author has changed it since, and carrying a note per
  resource that every moderator can read and edit. Resources removed
  automatically as abandoned courseware are not listed — no moderator hid
  them.
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
- **Editing what a resource says**: an **Edit details** button on the resource
  page opens a form for its title, description, subject tags and thumbnail —
  the counterpart of "Replace the file", which changes the package without
  touching the entry. The description is a rich-text field. What the form
  deliberately leaves out is the licence, because changing it would alter the
  terms people have already imported under, and the type/activity/format
  fields, which are read from the uploaded package rather than typed by
  anyone. The thumbnail lives here too rather than on the resource page.
- **Starring**: any signed-in visitor can star a resource. Starred resources
  appear under **Liked resources** on that person's educator profile, provided
  they have shared something themselves; only published resources are listed,
  since profiles are public. Stars are stored in Moodle's own favourites
  subsystem, so they are covered by core's privacy tooling.
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
  (`/local_oerexchange/u/{slug}`, bio/expertise/badges/metrics/resource grid,
  plus whichever of the site's additional user profile fields the admin has
  set to "Visible to everyone"), auto-created on first published resource.
  Author attribution
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

## A content area on the catalogue

**Show a content area on the catalogue** (off by default) puts admin-authored
HTML at the top of the Browse OER catalogue page — a welcome message, guidance
for contributors, or an announcement. It is a checkbox plus an HTML editor,
matching how core handles its own search banner: switching the checkbox off
takes the area down without discarding what you wrote.

The content is rendered by the catalogue itself, so it appears on every route
to that listing — the plugin's own page, and both ways the catalogue can serve
as the site home page (see below). It also shows on an empty catalogue, which
is when a welcome message is most useful.

## Anonymous access

Browsing the catalogue and viewing a resource page work without logging in.
Downloading a resource's `.mbz` does not, unless **Allow anonymous download**
is turned on — and that setting governs **Try it** as well, because a sandbox
trial downloads the `.mbz` itself to boot. With the setting off, an anonymous
visitor who clicks Try it is sent to the login page.

**Show the catalogue to visitors at the site home page** (off by default) goes
one step further: with it on, a visitor who is not logged in and opens the site
home page is served the catalogue *at that address*, instead of being sent to
the login form. The page is rendered in place, so the address bar stays at the
site root and searching from there stays there too. `forcelogin` can remain on
— it still applies to every other page; only the front page is opened. The
catalogue is not served while the site is in maintenance mode, and
`/?redirect=0` always reaches the normal front page. Implemented as a
`\core\hook\after_config` listener, which runs before core `index.php`'s
`require_course_login()`.

For logged-in users, the plugin adds an **OER catalogue** option to Moodle's
own *Appearance → Navigation → Default home page for users*
(`\core_user\hook\extend_default_homepage`); core handles that case by
redirecting to the catalogue's own address.

A "site key" issued on approval **is a real core web service token**, minted
against a dedicated, non-interactive Moodle account created for that site
(`local_oerexchange\local\site_manager`) — not a custom auth scheme.

## Uploading, and knowing what happened next

A share is published asynchronously: the file is stored immediately, and an
adhoc task validates the backup on the next cron run before the resource
appears in the catalogue. Both upload pages therefore show a **progress bar**
while the file is being sent (course backups here routinely run to hundreds of
megabytes, which a plain form post reports to nobody), and then land on the new
resource's own page, which says **"Checking your upload…"** and updates itself
to either *Published* or the rejection reason — no reload, no guesswork. The
progress bar is progressive enhancement: with JavaScript off, the same form
posts to the same address and the same thing happens, minus the bar.

## File size and the sandbox

Resource pages and catalogue cards show each resource's file size, and a
resource big enough to make an in-browser trial slow carries a note beside
**Try it** saying so and pointing at Download instead.

**Warn about slow trials above** (`sandboxwarnbytes`) sets that threshold. Its
default, 50 MiB, is the *stock* sandbox engine's fast-download budget: below
it, a trial fetches the backup with a visible percentage; above it, the engine
falls back to a download inside its WebAssembly PHP that reports no progress at
all. A 359 MB course measured on the stock budget booted successfully but took
4 minutes 15 seconds, nearly four of them apparently idle. Set it to 0 to never
warn.

**Keep this in step with your sandbox.** The companion `oer-sandbox` kit can
raise that budget when it builds the bundle
(`OER_FAST_DOWNLOAD_MAX_MB`, default 384). If yours is built that way, set this
setting to the same number — otherwise the Exchange warns about resources the
sandbox now handles quickly. On the reference deployment, raising the budget to
384 MiB took the same 359 MB course from 4 min 15 s to **23.8 seconds**, with a
percentage throughout.

**Never offer a trial above** (`sandboxmaxbytes`, 0 = no limit) is the hard
version: above it no Try it button is rendered and a direct link to the trial
is refused, with the size and the limit explained in place of the button. It is
off by default, because a large trial does work — it is only slow.

**Maximum upload size** (`maxbackupbytes`, 500 MB) is the one limit that
actually refuses an upload. It is enforced on every publish path, shown on the
upload pages, checked in the browser before a large file is sent, and
advertised to registered client sites so their share forms can check it first.

## Licence display

**Show licence codes in capitals** (`uppercaselicencenames`, on by default)
chooses whether a resource's licence code reads `CC-SA-4.0` or `cc-sa-4.0` on
resource pages, catalogue cards and the browse block. It is a display setting
only: codes are wrapped in `<span class="oer-licence-name">` and the capitals
come from CSS, so the licence is stored, filtered and advertised to client
sites exactly as it was published. The licence filter dropdown keeps the
stored spelling regardless. A theme can override the styling with
`.oer-licence-name--upper { text-transform: unset; }`, because plugin
stylesheets are emitted before theme CSS.

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
