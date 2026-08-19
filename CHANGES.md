# Release notes — 1.0.9

The Exchange now sees far more of what a shared course actually needs, can
re-check its whole catalogue on demand, and announces shares and updates as
events you can subscribe to.

## Deeper required-plugins detection

Until now the "Required plugins" list on a resource page was derived only
from the backup's manifest: activity modules and the course format. A course
could depend on a third-party **question type**, **question behaviour**,
**activity subplugin** (a quiz access rule, an assignment submission method,
a workshop grading strategy…), **advanced-grading method** (a custom rubric
variant) or **block**, and the page would still say "No non-core plugins
required" — and the sandbox trial would quietly restore without it.

Uploads are now scanned for all of those, in every backup era Moodle can
restore. Plugins that once shipped with Moodle and were later removed from
core (the `random` question type above all, ubiquitous in older quiz
backups) are recognised as core-handled and deliberately **not** reported —
they would otherwise show as a "missing" plugin nothing could ever install.
The resource page, the trial-availability badges and the client site's
preview all reflect the fuller list automatically.

## Re-scan the existing catalogue

Detection improvements would otherwise only benefit new uploads. A new
scheduled task, **Re-scan stored backups for required plugins**, re-derives
the list for every current version from its stored backup file. It changes
nothing else — statuses, files and thumbnails are untouched. It ships
disabled: run it on demand from *Server ▸ Scheduled tasks ▸ Run now* (or
`admin/cli/scheduled_task.php --execute`) after upgrading, and after any
future detection improvement.

## Events for monitoring

Two events now fire: **Resource shared** (a new catalogue entry, from either
upload page or a client-site share) and **Resource updated** (a replacement
file or an edited details form). Both are ordinary Moodle events: create a
rule in *Reports ▸ Event monitoring rules* to be notified when either
happens, or attach any event observer. Moderation actions fire neither.

# Release notes — 1.0.8

A maintenance release: the sandbox bundle defaults follow upstream Moodle
Playground's retirement of its v5.2.0 tag workaround, and the distribution
ZIP now includes the automated tests.

## Sandbox bundle defaults

Upstream Moodle Playground now has a real `MOODLE_502_STABLE` branch, so the
default bundle list no longer pins it to the `v5.2.0` tag — a bare branch
name builds the branch tip. The help text for the **Sandbox bundles** setting
(English and Japanese) has been corrected to say so; it used to claim a bare
branch name "uses its default tag". A saved setting is not touched by this
change: sites that pin a tag keep their pin.

## Packaging

The `tests/` directory ships in the distribution ZIP, so a downloaded release
can run the plugin's PHPUnit suite as-is.

# Release notes — 1.0.7

Authors can edit what a shared resource says, visitors can star resources, and
moderators get a standing list of what they are holding — plus a fix for a
takedown that the author being moderated could undo.

## Editing a shared resource

Until now a resource's title and description were fixed at the moment it was
shared: correcting a typo meant sharing the whole thing again. An **Edit
details** button on the resource page now opens a form for the title,
description, subject tags and thumbnail — the counterpart of "Replace the
file", which changes the package without touching the entry.

The description is a rich-text field rather than a plain box. The thumbnail
moved here too, replacing the upload control that used to sit on the resource
page itself, so everything that changes how an entry reads is in one place.

Two things are deliberately not editable. The **licence** is left out because
changing it would alter the terms under which people have already imported the
material. The type, activity type and course format are read from the uploaded
package rather than typed by anyone.

## Starring, and "Liked resources"

Any signed-in visitor can star a resource. Starred resources appear under
**Liked resources** at the bottom of that person's educator profile, provided
they have shared something themselves. Only published resources are listed:
profiles are public, so somebody else's bookmark must not advertise a resource
that has since been hidden or taken down.

Stars are stored in Moodle's own favourites subsystem rather than a table of
this plugin's own, so they are covered by core's privacy tooling and are
removed with a user's data in the usual way.

## A report of what moderators are holding

A new moderator-only page lists every resource a moderator has taken down,
reachable from **Site administration ▸ Plugins ▸ Local plugins ▸ OER
Exchange**, and linked from the moderation-queue block with a count. For each
resource it records when it was taken down and by whom, flags whether the
author has changed it since, and carries a note that every moderator can read
and edit.

Resources removed automatically as abandoned courseware are not listed — no
moderator hid them. Resources taken down before this release have no recorded
date or moderator, and the report says so rather than showing a made-up one.

## Moderators can take a resource down from its own page

Previously the only way to take a resource down was from a row in the
moderation queue, which meant a resource nobody had reported could not be taken
down at all. Moderators now have their own **Take down** and **Restore**
controls on the resource page.

This also closes a real gap. The Hide button on a resource page is the
*author's* switch, and moderators could see it — so a moderator hiding somebody
else's resource wrote the author's own "hidden" status, which that author could
simply switch back, and which no moderation report listed. The author's
hide/show switch now requires authorship; moderators use Take down, which only
a moderator can lift.

## A content area on the catalogue

**Show a content area on the catalogue** (off by default) puts admin-authored
HTML at the top of the Browse OER catalogue page — a welcome message, guidance
for contributors, or an announcement. Switching the checkbox off takes the area
down without discarding what you wrote. It appears on every route to the
catalogue, including both ways the catalogue can act as the site home page.

## Fixes

- The resource-type badge on an educator profile read "Course" for every
  resource whatever its type, so data resources and activities were
  mislabelled. The catalogue was already correct; both now share one
  definition.
- Public custom profile fields are shown through the site's text filters, so a
  URL typed into a profile field becomes a link as it already did in a bio.
  Values are still cleaned, so unsafe markup remains neutralised.
- Moderator names on the new report are escaped where they are rendered.

## Upgrade notes

The resources table gains a `summaryformat` column and three columns recording
a takedown, and a new `local_oerexchange_modnotes` table stores moderator
notes. Existing descriptions are treated as HTML, which is what every page
already assumed.

No action is required beyond the usual `admin/cli/upgrade.php`.

## Checks run for this release

`moodle-plugin-ci` phplint, phpmd, phpcs (`--max-warnings 0`), phpdoc
(`--max-warnings 0`), validate, savepoints and mustache — the same commands
this project's GitHub workflow runs — all exit 0, and the runner was confirmed
to report a failure when a docblock was deliberately broken.
`scripts/verify-gates` exits 0, so the local gates are known to fire on bad
input. `local_moodlecheck` reports no findings. PHPUnit: **590 tests, 1558
assertions**, all passing.

Every behaviour above was exercised in a browser against a live site —
including the moderator takedown performed against a resource the acting
moderator does not own, and a forged author-hide request confirmed refused.
