# Release notes — 1.0.11

The sandbox plugin allowlist is now read and edited per plugin rather than per
plugin-and-Moodle-version, and adding or removing a Moodle version no longer
means deleting the plugin and starting again.

## One line per plugin

The allowlist stores one row per plugin per Moodle version, because each
version carries its own mirrored ZIP, its own pinned release and its own
"bake into bundle" flag. The table showed exactly that: a plugin offered on
5.0 and 5.2 appeared twice.

It now groups by plugin. The Moodle versions sit together in one column, and
each keeps the things that genuinely differ between versions — its bake
checkbox, its enable/disable control, the warning when it was added for a
version the plugin does not claim to support, and its release number when
that differs between versions. Nothing about the stored rows changed, so the
sandbox configuration file and every other consumer read them exactly as
before.

## Adding and removing Moodle versions

Each listed version has its own **Remove** button. Removing one leaves the
plugin listed for the others; removing the last one removes the plugin, with
the same confirmation as before.

A **+ 5.x** button appears for every Moodle version the sandbox deploys that
the plugin is not yet listed for. It reuses the release already mirrored for
that plugin rather than fetching a different one — the package an
administrator reviewed stays the package that is offered — and needs no
network access.

If the plugin's own `version.php` does not claim to support the version being
added, it is still added, and the row keeps the existing "added for a Moodle
version this plugin does not claim to support" warning. A `supported` range
that nobody updated is the common case, and the warning keeps the decision
visible.

## Offering a selection on a new Moodle version

When a new Moodle version is added to the sandbox, a control below the table
lists everything already offered on one version onto another in a single step.

Unlike the per-plugin button, each plugin here is looked up in the Moodle
plugins directory again **for the target version**, because a new Moodle
release is exactly when a newer release of a plugin is likely to be the
compatible one. It is a preview: the same "what will be added" screen a
manual addition shows, with nothing written until it is confirmed. A plugin
the directory cannot resolve appears as a visible unresolved entry rather
than being quietly skipped.

## Bake flag and capabilities

Adding a Moodle version to a plugin copies its bake flag only for
administrators holding `local/oerexchange:managesandbox`. Without it the
version is still added, but unbaked: extending a baked plugin to another
version changes what the next sandbox bundle build ships, which is precisely
what that capability governs.

## Checks run for this release

`moodle-plugin-ci` phplint, phpmd, `phpcs --max-warnings 0`,
`phpdoc --max-warnings 0`, validate, savepoints and mustache each exited 0,
matching this repository's own CI workflow. PHPUnit: 11 new tests covering
the new behaviour and 101 tests across the allowlist suite, all passing. The
reworked page was exercised in a browser on the test site — adding a version
to a plugin, then removing it again — and the sandbox configuration stamp
returned to its previous value afterwards, confirming the deployed bundle
still matches the configuration.
