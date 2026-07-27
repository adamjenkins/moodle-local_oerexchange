# Release notes — 0.1.4

Two additions, both about keeping the catalogue trustworthy.

**Abandoned courseware** (new, off by default): when enabled, a nightly check
warns the author of any published resource that has gone unmaintained past a
configurable threshold (default two years). The author can update the
resource or press a one-click "Still fresh" button — on the resource page and
linked from the warning — to reset the clock. If neither happens within a
configurable grace period (default 60 days), the resource is removed from the
catalogue. Removals land in the moderation page's restorable list, so a
moderator can always bring one back (doing so also resets its clock).
Resources with no reachable author are never removed automatically.

**Share-button logos**: the share buttons on resource and profile pages now
show each network's own logo (Mastodon, Facebook, X, LinkedIn, and glyphs for
copy link, email, SMS and native share), rendered from the FontAwesome that
ships with Moodle itself — no icon artwork or FontAwesome copy is bundled
into the plugin, and the text labels remain for accessibility.
