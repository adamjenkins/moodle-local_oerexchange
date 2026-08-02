# Release notes — 1.0.6

A small follow-up to 1.0.5, with one new ability on the sandbox plugin
allowlist and one installation fix.

## Allowlist entries can be deleted, not only disabled

Every entry on the sandbox plugin allowlist now has a **Delete** button beside
Disable.

The two are deliberately different. **Disable** is reversible: the entry stays
on the list, keeps its settings, and can be switched back on whenever you like
— the right choice for "not just now". **Delete** removes the entry and the
copy of the plugin ZIP stored with it, which is what you actually want for an
entry that should never have been there: a plugin added by mistake, or one
superseded by something else. It asks for confirmation first, because unlike
everything else on that page it cannot be undone.

If other plugins were added automatically as **dependencies** of the one you
delete, they are **kept**. A dependency can be shared with another plugin, so
removing entries you did not name would be a worse surprise than leaving one
behind; they simply stop being marked as belonging to the deleted entry. The
confirmation tells you how many there are before you commit to anything.

## Installing no longer emits a database warning

Installing the plugin produced a warning from Moodle's database layer about
the `component` column added in 1.0.5 declaring an empty string as its
default. Moodle corrected the column itself and carried on, so the resulting
database was never wrong and no site needs to do anything — but the message
caused automated plugin checks to report the installation as failed.

The column is now declared the way Moodle asks for, and installing produces no
warnings.

## Checks run for this release

`scripts/verify-gates` (proving each gate fires on known-bad input), then
`scripts/phpcs-ci` — clean; `local_moodlecheck` — clean (docblock/signature
consistency only); PHPUnit — 500 tests, 1284 assertions, all passing. A fresh
install from `db/install.xml` was performed and confirmed to emit no debugging
output. Deleting an entry was exercised against a live site, confirming the
row, its stored ZIP and nothing else were removed.
