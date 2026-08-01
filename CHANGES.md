# Release notes — 1.0.3

## A trial's language switcher now works, and offers the languages you configured

Two separate problems stopped a "Try it" trial ever showing Moodle's language
switcher, so a bilingual resource could only be viewed in one language:

- **The sandbox forced the switcher off.** The playground's generated
  `config.php` assigned `$CFG->langmenu = 0`, and a value assigned in
  `config.php` is a *forced* setting that overrides the database — so the
  `langmenu=1` an admin configured on the Sandbox bundle configuration page was
  applied, written into the bundle's install snapshot, and then ignored at
  runtime, with no error anywhere. Fixed in the `oer-sandbox` build scripts
  (`scripts/patch-playground.mjs`), which means **the bundle must be rebuilt**
  for the setting to take effect; nothing in this plugin can compensate for a
  bundle built before that change.
- **A configured language pack was never installed.** A trial only ever
  installed a pack for the language it opened in — normally the launching
  user's own. With a Japanese pack configured and an English-speaking visitor,
  the pack shipped inside the bundle and was then never installed, so the trial
  had exactly one translation, and Moodle hides the switcher below two. A trial
  now installs the language it opens in *plus* every pack the sandbox
  configuration names.

The trial language still decides only which of the installed languages the
trial *opens* in — the rest are there so a visitor can switch.

Verified end to end against the deployed sandbox: an English visitor's trial
opens in English, offers 日本語 (ja) in the user menu's language selector,
renders Japanese after switching, and offers English back.

No database changes. No action is required after upgrading beyond the usual
`admin/cli/upgrade.php` — but a sandbox bundle rebuilt with the current
`oer-sandbox` scripts is required for the switcher itself.
