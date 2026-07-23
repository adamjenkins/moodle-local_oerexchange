# Release notes — 0.1.2

Completes the author-control round.

A moderator's takedown is now a distinct state (`modhidden`) that an author
cannot lift — previously a moderator hide wrote the same status as the
author's own Hide button, so an author could silently undo it. The
moderation queue gains a **Moderated resources** section with a Restore
action, which is also the first way a takedown could be reversed at all.

Authors can replace a published resource's file without touching its
catalogue entry, via **Replace the file** on the resource page.

Also: the Japanese language pack is complete again (82 missing strings), and
the test suite has moved off PHPUnit's deprecated doc-comment metadata.

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
