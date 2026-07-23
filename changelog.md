# Changelog

All notable changes to this project are documented in this file, in
[Keep a Changelog](https://keepachangelog.com/) format.

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
