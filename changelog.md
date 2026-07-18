# Changelog

All notable changes to this project are documented in this file, in
[Keep a Changelog](https://keepachangelog.com/) format.

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
