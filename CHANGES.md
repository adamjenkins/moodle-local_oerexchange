# Release notes — 0.1.1

Author control over shared resources, and sharing affordances.

Authors can now hide, show and delete their own resources; decline sandbox
("Try it") availability with an optional reason; and update the copy the
Exchange serves without creating a duplicate catalogue entry. The Exchange
now serves exactly one version per resource — an update supersedes the
previous one and deletes its file, keeping the row so existing
import/trial references still resolve.

Profile and resource pages gained a share affordance whose destinations are
admin-configurable (copy link, native share, Mastodon, Facebook, X,
LinkedIn, email, SMS). It replaces a profile share button that silently did
nothing. Every network target is a plain link — no third-party script is
loaded onto a catalogue page.

# Release notes — 0.1.0

Initial alpha release: catalogue, site registration + account-linking
identity, publish/search/get_resource/record_import/get_config web services,
.mbz structure-preview parser with required-plugins derivation, server-side
sanity check (no user data), reviews/reports/moderation queue, GDPR privacy
provider, and Moodle Playground sandbox integration (trial launch URLs, no
server-side execution).
