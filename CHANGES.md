# Release notes — 1.0.5

## Before you upgrade: this release changes the database

Upgrading runs a migration on the sandbox plugin allowlist
(`local_oerexchange_pluginallowlist`). It adds four columns, and then makes the
combination of plugin type, plugin name and Moodle version **unique** — which
is what lets re-adding a plugin update its entry instead of quietly creating a
second one.

**If your allowlist already contains more than one entry for the same plugin
and the same Moodle version, the extras are deleted.** Earlier versions did not
prevent those, so they can exist. The migration keeps the most recently added
entry of each set and removes the others, together with their mirrored ZIP
files; anything recorded only on a removed entry — its enabled/disabled state,
its "bake into bundle" flag — goes with it.

Most sites have no duplicates and will notice nothing. If you want to check
first, look at Site administration → Plugins → Local plugins → OER Exchange →
Plugin allowlist for a plugin listed twice against the same Moodle version.

## You can now see what your upload is doing

Sharing a large course backup used to look like nothing was happening. The
browser reports nothing during an ordinary form upload, and a course backup
here can run to hundreds of megabytes — so the page simply sat there, sometimes
for minutes, with a Share button that appeared to have been ignored.

The upload pages now show a progress bar with a percentage while the file is
being sent, followed by a distinct **"Upload complete — validating…"** stage
once the last byte has left your browser and the server takes over. The Share
button is disabled while the upload runs, so a second click cannot start it
again. If JavaScript is switched off, the form still works exactly as before.

## And what happened to it afterwards

Publishing is not instant: your file is stored straight away, but the backup is
validated by a scheduled task, usually within a minute, and only then does the
resource appear in the catalogue.

Clicking Share used to take you to the catalogue with a one-word notification
reading "Share" — no link, no status, and the catalogue is the one page that
cannot show a resource still waiting to be validated. You now land on your new
resource's own page, which says **"Checking your upload…"** and then updates
itself, without a reload, to either *Published* or the reason the backup was
rejected.

## File sizes are visible before you click

Resource pages and catalogue cards now show how big each resource is. Until
now, the only way to find out was to start the download.

## Adding a sandbox plugin is now one box, not two formfuls

Putting a plugin on the sandbox allowlist used to mean typing its type, its
name, a Moodle version and a source URL, then uploading a zip you had
repackaged yourself so its top-level folder was the plugin's own directory
name — and then doing the whole thing again for the second Moodle version.

Now you paste the plugin's address, or upload its zip, and press **Look it
up**. A GitHub repository address works directly: its latest release is used,
or its default branch if it has never cut one. Nothing is saved until you have
seen a summary of exactly what will be added and pressed **Add these entries**.

Everything else is read out of the package:

- **The plugin type, name and release number**, so there is nothing to type.
- **Which Moodle versions it supports** — and an entry is created for each one
  the sandbox runs. A plugin supporting both 5.0 and 5.2 now takes one
  submission instead of two. The summary tells you whether the plugin declared
  its supported versions outright, whether they were inferred from the minimum
  Moodle version it requires, or whether it said nothing and every version is
  simply being offered.
- **Other plugins it needs.** These are fetched, added as active entries, and
  shown as dependencies of the plugin that asked for them. Ones that ship with
  Moodle are skipped, because a trial already has them. Ones published nowhere
  public are listed as *"Needed, but no download found"* so you can add them
  yourself — they are never silently omitted.

Two smaller things fall out of this. You no longer repackage a zip: whatever
its top-level folder is called, it is repacked under the plugin's real
directory name. And re-adding a plugin now updates it and replaces its stored
zip, instead of quietly creating a second set of entries.

If you tick **Bake into bundle** when adding, it applies to the plugin's
dependencies too — a baked plugin whose dependency only arrives when a trial
boots would not actually work.

**When a plugin's declared versions are just out of date.** Plugins frequently
carry an old supported range because nobody updated the line, and run perfectly
well on newer Moodle regardless. Previously such a plugin could not be
allowlisted at all — it matched no version the sandbox runs, and that was that.

Tick **"Add it for Moodle versions it does not claim to support"** and it is
listed for every version the sandbox runs, whatever its `version.php` says.
This covers its dependencies as well, since listing one without the other would
not work. What it deliberately does *not* override is a plugin declaring itself
outright **incompatible** with a version — that is the maintainer saying it is
broken, not forgetting to update a line.

The confirmation page names exactly which versions you are adding it for
against its own declaration, and those entries stay marked in the list
afterwards, so nobody later mistakes a deliberate decision for a bug. It is
worth actually opening a trial for such a plugin.

The same thing is available from a shell as
`cli/add_allowlist_plugin.php --url=… --dry-run`.

## The upload page tells you the size limit before you upload

The largest file this Exchange accepts was previously invisible: it had no
setting an administrator could see, and an author could only discover it by
uploading a large backup and being rejected on arrival. The upload pages now
state it, and if you pick a file over the limit your browser says so
immediately and sends nothing. The same limit is still enforced on the server
for every path, including client sites sharing over the web service.

**Maximum upload size** is now an ordinary setting (Site administration →
Plugins → Local plugins → OER Exchange), defaulting to the 500 MB this plugin
has always enforced.

Picking a file that is fine to upload but awkward for the in-browser trial now
says so too, at the moment you choose it rather than after publishing.

## Turning off trials for very large resources

**Never offer a trial above** is a new setting for administrators who would
rather a visitor downloaded a large resource than waited for it. Above the size
you set, the Try it button is not shown — the resource page explains why and
points at Download — and a direct link to a trial is refused.

It is **off by default**, and deliberately so: a large trial does work. What it
does is take time.

## A warning before a slow in-browser trial

**Try it** boots a whole Moodle in the visitor's browser, and it must download
the entire backup before it can start. Past a certain size the sandbox stops
reporting progress on that download, so a large resource looks like a broken
button for several minutes. Measured on a 359 MB course: it does work, but it
takes about four minutes, almost all of it apparently idle.

Resources above the threshold now carry a note beside the Try it button saying
how big the file is, what to expect, and that downloading it and restoring it
on your own Moodle is quicker. **Nothing is refused on size** — the button is
still there.

A new setting, **Warn about slow trials above** (Site administration → Plugins
→ Local plugins → OER Exchange), sets the threshold. Its default, 50 MB, is the
size at which a stock sandbox stops reporting download progress. Set it to 0 to
switch the warning off entirely.

If your sandbox is built by the companion `oer-sandbox` kit, that kit can now
raise the size a trial downloads at full speed (its `OER_FAST_DOWNLOAD_MAX_MB`
build option, 384 MB by default) — set this setting to the same number so the
Exchange stops warning about resources your sandbox handles quickly. On the
reference deployment that took the same 359 MB course from 4 minutes 15 seconds
to **23.8 seconds**, with a percentage shown throughout.

## Under the hood

- New AJAX-only web service function `local_oerexchange_get_publish_status`,
  which answers only for resources the caller may edit (its author, a
  co-author, or a moderator) and never returns raw server error text.
- New AMD modules `local_oerexchange/upload_progress` and
  `local_oerexchange/publish_status`.
- New settings `sandboxwarnbytes` (52428800), `sandboxmaxbytes` (0 — no trial
  cap) and `maxbackupbytes` (524288000 — the limit this plugin already
  enforced, now visible and editable).
- The maximum accepted upload size now has one definition
  (`size_advice::max_upload_bytes()`) behind the three places that used to
  carry their own copy of it: `publish()`, the `get_config` web service and the
  upload pages. A site that changed it could previously advertise one number to
  a client site and enforce another.

## Checks run for this release

`scripts/verify-gates` (proving each gate fires on known-bad input) followed by
`scripts/phpcs-ci` — clean; `local_moodlecheck` — clean (docblock/signature
consistency only); PHPUnit — 492 tests, 1260 assertions, all passing. Verified
in a browser against a live site: the progress bar, the publish-status updates,
both upload outcomes, the file sizes, the slow-trial warning, the trial cap
(both the resource page and a direct link to the trial), the stated upload
limit, and a file over that limit being refused before anything was sent.
