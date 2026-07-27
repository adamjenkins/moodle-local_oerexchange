# Release notes — 0.1.6

Co-authorship, catalogue thumbnails, and the fixes from a pre-submission
security self-audit.

**Co-authors.** An author can now name a second (and third, and fourth)
author on anything they have shared, by username or email address. A
co-author holds full parity with the creator: replace the file, change the
thumbnail, hide or delete the entry, opt out of Try it, confirm freshness,
and manage the co-author list itself. This is one row in one new table
consulted by the single gate every author-side action already went through,
so the parity is structural rather than a list of features that has to be
kept in step. The creator is never a row in that table, which is what makes
them unremovable by someone they added. Co-authors are shown publicly on the
resource page, linked to their educator profiles.

**Thumbnails.** The catalogue page and the three Exchange Dashboard blocks
now lead with each resource's cover image. Every item gets an equally sized
thumbnail slot — a neutral panel where there is no cover — so a grid of
cards and a list of block rows both keep their alignment instead of stepping
around the items that happen to have a picture.

**Security self-audit fixes.** The registered-sites table was declared as
personal data but serviced by no export or deletion path; it now is, with
erasure scrubbing the contact and deliberately keeping the registration so a
live third-party integration is not severed. Uninstalling the plugin left
one service account per registered site behind, each with a valid web
service token — both are now removed, and only accounts whose username still
matches the form this plugin mints are touched. The sandbox launcher handed
anonymous callers a signed backup URL in a redirect parameter regardless of
the anonymous-download setting; it is now gated on that setting, so on a
default configuration an anonymous visitor pressing Try it is sent to log in.
The two endpoints that accept unauthenticated writes are now bounded:
registration reuses an existing pending row for the same URL and caps new
registrations per hour, and sandbox trials collapse to one row per viewer per
resource per five minutes. Account-link callbacks are compared on scheme as
well as host, so an https-registered site can no longer be handed a plaintext
callback carrying its one-time link code.
