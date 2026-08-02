# Release notes — 1.0.5

> **Draft.** More work is going into this release. Before tagging: remove this
> note, add the remaining entries, set the date on the `[1.0.5]` heading in
> `changelog.md`, and bump `$plugin->version` if any code changed after
> `2026080200`.

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
exact size at which the sandbox stops reporting download progress. Set it to 0
to switch the warning off entirely.

## Under the hood

- New AJAX-only web service function `local_oerexchange_get_publish_status`,
  which answers only for resources the caller may edit (its author, a
  co-author, or a moderator) and never returns raw server error text.
- New AMD modules `local_oerexchange/upload_progress` and
  `local_oerexchange/publish_status`.
- New setting `sandboxwarnbytes` (default 52428800). The separate maximum
  upload size a site will accept at all is unchanged.

## Checks run for this release

`scripts/verify-gates` (proving each gate fires on known-bad input) followed by
`scripts/phpcs-ci` — clean; `local_moodlecheck` — clean (docblock/signature
consistency only); PHPUnit — 407 tests, 1055 assertions, all passing. The
progress bar, the publish-status updates, both upload outcomes, the file sizes
and the slow-trial warning were each verified in a browser against a live site.
