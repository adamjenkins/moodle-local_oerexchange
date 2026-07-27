# Changelog

All notable changes to this project are documented in this file, in
[Keep a Changelog](https://keepachangelog.com/) format.

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
