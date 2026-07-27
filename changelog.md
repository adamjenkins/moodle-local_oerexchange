# Changelog

All notable changes to this project are documented in this file, in
[Keep a Changelog](https://keepachangelog.com/) format.

## [0.1.6] - 2026-07-27

### Added

- **Co-authors.** An author can add further authors to a resource they have
  shared, naming them by username or email address (both matched
  case-insensitively; username wins when a string is one person's username
  and another's email). New table `local_oerexchange_coauthors`
  (`resourceid`, `userid`, `addedby`, `timecreated`) with a unique
  `(resourceid, userid)` index.
- A co-author holds **full parity** with the creator — file replacement,
  thumbnail, hide/unhide, delete, Try-it opt-out, freshness confirmation,
  and the co-author list itself. `resource_manager::user_can_edit_resource()`
  is the single gate all of those already consulted, so admitting a
  co-author there admits them everywhere at once. The creator is never a
  co-author row, so nobody they add can remove them.
- Co-authors are shown publicly on the resource page ("With ..."), linked to
  their educator profiles where those are visible.
- New `coauthor` message provider: the person added is told, by name, which
  resource and who added them.
- New `classes/local/coauthor_manager.php` and 18 unit tests covering
  identifier resolution, the five refusal cases, parity, chain-adding,
  revocation and the GDPR paths.
- **Cover-image thumbnails** on `index.php` and in the `oerexchangebrowse`,
  `oerexchangequicklinks` and `oerexchangeshares` blocks, via new
  `classes/local/cover_image.php`. Resources with no cover get an
  equally sized neutral panel so grids and lists stay aligned; the URL
  lookup is a single batch query per page rather than one per item.
  The quick links block's thumbnail is also its first-ever route to the
  resource page.

### Security / Privacy

- `local_oerexchange_sites` was declared in the privacy metadata from 0.1.5
  but appeared in no export path, no delete path and neither discovery
  method. Both links that exist — `serviceuserid`, and `contact` matching a
  local user's email — are now serviced. Erasure **scrubs the contact and
  keeps the registration**: deleting the row would sever a live third-party
  integration whose service account and token remain valid.
- `db/uninstall.php` now deletes each registered site's service account and
  its web service tokens. Previously both survived the uninstall with the
  only record of which accounts were ours destroyed alongside the tables.
  Only an account whose username still matches the `oersite_<siteid>` form
  this plugin mints is deleted.
- `sandbox_launch.php` is gated on the `anonymousdownload` setting. It
  minted a 15-minute signed `.mbz` URL and base64'd it into the redirect's
  `blueprint` parameter, readable straight out of the `Location` header, so
  "Try it" was a two-step way around a gate that is off by default.
  **Behaviour change**: on a default configuration an anonymous visitor
  pressing Try it is now sent to the login page.
- `register.php` reuses an existing pending registration for the same URL
  (matched case-insensitively) and refuses beyond 30 new registrations per
  hour with a `429`. Sandbox trials collapse to one row per viewer per
  resource per five minutes. Both endpoints accept unauthenticated writes
  and were previously unbounded.
- `connect.php` compares the callback's scheme as well as its host
  (`link_manager::callback_matches_site()`), so an https-registered site can
  no longer be handed an `http://` callback carrying its one-time link code.
- Privacy provider services the new co-authors table in both directions: a
  user's own co-authorships are exported and erased, while grants they made
  to *other* people survive with the `addedby` attribution scrubbed to 0 —
  revoking a third party's editing rights is not part of this user's
  erasure. Tombstoning a resource drops its co-author rows.

### Changed

- `resource_manager::publish()`'s update branch uses the shared edit gate
  instead of a bare `creatorid` comparison. This is what lets a co-author
  replace the file, and incidentally settles a pre-existing inconsistency
  where both upload pages admitted a moderator only for `publish()` to
  reject them. Replacing the file still never transfers ownership.
- `get_share_status` (web service) uses the same shared gate, so a co-author
  polling status from their own client site sees the entry they can edit.
- The three Exchange blocks lay their rows out as thumbnail-left,
  text-right.

### Fixed

- The co-author notification's subject used the `{$a->title}` form while the
  caller passes a plain title, so it went out reading literally
  `You are now a co-author of "{$a->title}"`. Caught on the live site, not
  by the tests that existed at the time; there is now a test asserting no
  unsubstituted placeholder survives in either the subject or the body.
- A Behat scenario in `oerexchange_admin_settings.feature` had been asserting
  against **OER Client's** settings page: both plugins publish a page called
  "General settings" under Local plugins, and the single-path navigation step
  resolved to the wrong one without failing. It now navigates to the
  plugin's own category and follows the link from there.

## [0.1.5] - 2026-07-27

### Security / Privacy

- Privacy metadata now declares `local_oerexchange_sites` (its `contact`
  column is a person's email address, regardless of it mapping to no local
  account).
- `delete_data_for_all_users_in_context()` now deletes every user-keyed
  row and tombstones every attributed resource (it was an unconditional
  no-op, silently retaining profiles, badges, reviews and reports through
  an approved delete-all request). The tombstoned catalogue skeleton
  survives by design.
- License shortnames are validated against core's license manager and
  titles are checked non-empty server-side on every publish path (WS,
  .mbz upload, data upload).
- The data-upload MIME sniff is per extension: the flat shared list let
  `application/octet-stream` neutralise the check for every type, so any
  binary renamed to `.pdf` passed.
- Account-link codes are claimed with an atomic conditional UPDATE —
  two concurrent requests could previously both consume the same code.
- `register.php` validates url/contact lengths (and name multibyte-aware)
  before insert instead of dying on a DB-level error.

### Fixed

- Moderation: hide/remove only applies to `published`/`hidden`/`pending`
  resources — a `deleted` tombstone could previously be taken down and
  then "restored" to published as a scrubbed, fileless husk. Restore now
  warns and refuses when the resource has no validated version to serve.
- The abandoned-courseware removal pass re-checks that the author is
  still reachable, matching the warning pass: an author deleted during
  the grace period leaves removal to a human moderator (new pinning test).
- Cover images follow the resource page's own access rule (creator or
  moderator for non-published) — authors saw a broken image on their own
  hidden/pending resources.
- Report/review/thumbnail/owner actions throw on a missing or expired
  sesskey instead of silently discarding the submitted text.
- The 2026072300 upgrade step no longer calls the plugin's own API
  (inlined with identical behaviour); `install.xml`'s VERSION attribute
  updated; a dead duplicate query removed from `get_resource`; thumbnail
  alt text no longer double-escapes; the badges task test no longer
  reports risky output; two hard-coded strings ('Error', '(deleted)') are
  now translated (EN+JA).

## [0.1.4] - 2026-07-27

### Added

- Abandoned-courseware lifecycle, off by default. Three new admin settings
  (enable, threshold — default 2 years, grace period — default 60 days), a
  nightly `check_stale_resources_task`, a `stale` message provider, and two
  new columns on the resources table (`timefresh`, `stalenotifiedtime`).
  A stale published resource's author is warned once; updating the resource
  or the one-click "Still fresh" button (resource-page banner, linked from
  the warning) resets the clock; otherwise the resource is removed when the
  grace period ends. Automatic removal writes the same restorable 'removed'
  status a moderator's takedown uses, and a moderator restore now also
  resets the freshness clock so a restored resource is not removed straight
  back. Resources with no reachable author are skipped entirely.
- Share buttons now show each destination's logo (brand icons for Mastodon,
  Facebook, X and LinkedIn) from Moodle's bundled FontAwesome via the
  standard icon-map callback. Icons are decorative and aria-hidden; labels
  are unchanged. On a theme whose icon system is not FontAwesome the buttons
  stay text-only.

## [0.1.3] - 2026-07-23

### Added

- A sandbox trial boots in the launching visitor's own interface language:
  the blueprint carries an `installLanguagePack` step for any non-English
  `current_language()`, installed with `setDefault` so the trial's
  auto-logged-in admin actually reads it. English emits no step at all.
  A language code that is not a Moodle language code is dropped rather than
  passed to the sandbox, which interpolates it into generated PHP and a
  dataroot path.

### Changed

- The blueprint's `login` step now follows the language step rather than
  preceding it. Installing a pack after login has no visible effect: Moodle
  serialises `$USER` into the session at login, so the language change lands
  too late for that session to read, and the trial renders in English
  despite a completely successful install.

## [0.1.2] - 2026-07-23

### Added

- **Moderated resources** section on the moderation queue, listing everything
  a moderator has hidden or removed, with a Restore action. Until now a
  moderator takedown could not be reversed from anywhere in the interface.
- **Replace the file** on a resource's owner card: re-upload a `.mbz` or data
  file for an already-published resource. Reuses the existing upload pages in
  an update mode, so file validation stays in one place. Metadata is not
  editable there — only the file changes — and the type is locked both ways.
- Japanese translations for the 82 strings that had fallen behind English
  (profiles, badges, thumbnails, data resources, privacy metadata).

### Changed

- A moderator hide now writes `modhidden` rather than `hidden`. `hidden` is
  the author's own switch; `modhidden` and `removed` are moderator states only
  a moderator can lift. The author can still view their own moderator-hidden
  resource and is told a moderator hid it.
- Test classes use PHPUnit attributes (`#[CoversClass]`, `#[DataProvider]`)
  instead of doc-comment metadata, which PHPUnit 11 deprecates and 12 drops.

### Fixed

- An author could un-hide a resource a moderator had hidden, silently undoing
  the takedown. Both actions wrote the same status.
- `trydisabledreason` is now declared in the privacy provider — it is
  author-written free text.

## [0.1.1] - 2026-07-23

### Added

- Share affordance on educator profile pages and resource pages, with an
  admin setting choosing which destinations are offered (copy link, native
  `navigator.share`, Mastodon, Facebook, X, LinkedIn, email, SMS). Every
  network target is a plain link to that network's own share endpoint; no
  third-party script, widget or tracking pixel is loaded. Adds the plugin's
  first AMD module, `local_oerexchange/share`.
- Authors can hide and show their own resources, and delete them (tombstone:
  files, versions, reviews and reports removed, row kept so existing links
  resolve to the "no longer available" message).
- Authors can turn off "Try it" for a resource and give an optional reason,
  which is shown in place of the button. `sandbox_launch.php` enforces it
  independently of the button being hidden.
- `local_oerexchange_get_share_status` web service: lets the client site
  that published a resource read back its status, first-published and
  last-updated times, and download/import counts. Answers only for the
  caller's own resources, so it can safely report hidden ones.

### Changed

- The Exchange now serves exactly one version per resource. Updating uploads
  a new version and, once it validates, supersedes the previous one —
  deleting its file while keeping its row so `imports.versionid` and
  `trials.versionid` never dangle. Superseding deliberately happens after
  validation, so a failed update leaves the previous good version serving.
- A profile's owner now sees their own hidden resources in their profile
  listing, flagged as hidden.

### Fixed

- The "Share my profile" button did nothing visible. It tried
  `navigator.share`, fell back to `navigator.clipboard.writeText`, and gave
  no feedback on any path, swallowing the clipboard rejection in an empty
  catch. `navigator.share` is undefined on most desktop browsers, so the
  realistic path was a clipboard write that either succeeded or was denied,
  silently, in both cases looking exactly like a dead button.
- Viewing a non-published resource was gated on the moderator capability
  alone, so an author who hid their own resource was locked out of the page
  that could unhide it.

## [0.1.0] - 2026-07-18

### Added

- Catalogue: publish, browse, search, resource detail page with structure
  preview.
- Identity: client-site registration + admin approval (dedicated non-login
  service account, real WS token as the "site key"), personal
  account-linking handshake (`connect.php`, one-time link codes).
- Web services: `search`, `get_resource`, `publish_resource`,
  `record_import`, `get_config`.
- `.mbz` structure-preview parser (`mbz_parser`) with required-plugins
  derivation against core's standard-plugins list.
- Server-side sanity check rejecting backups containing user data.
- Reviews (adaptation stories), reports, and a moderation queue.
- Sandbox plugin allowlist (curated contrib plugins, same-origin mirrored
  ZIPs) and Moodle Playground trial-launch integration — no server-side
  trial execution, no Podman fleet.
- HMAC-signed short-lived download URLs for both WS clients and sandbox
  trials.
- GDPR privacy provider.
