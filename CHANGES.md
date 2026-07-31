# Release notes — 1.0.2

## Bilingual catalogue titles and descriptions now render correctly

Resource titles, descriptions, catalogue-card summaries and structure-preview
section/activity names authored with multilang markup
(`<span lang="en" class="multilang">…</span><span lang="ja" class="multilang">…</span>`)
previously showed the raw markup as visible text everywhere on this site, even
with the multilang filter correctly enabled — a live, user-reported bug. These
sinks now render through the site's filters, so a bilingual resource shows in
whichever language the viewer has selected, on the catalogue, the resource
page and the three Exchange Dashboard blocks that display resource titles.

## New: sandbox bundle configuration

An admin can now compose what a "Try it" trial bundle is built with, from a
new **Sandbox bundle configuration** page (Site administration → Plugins →
OER Exchange → Sandbox bundle configuration):

- Which language packs to bake in, and the trial's default language.
- Which Moodle branches to build.
- Whether the multilang filter (and, separately, multilang for headings and
  names) is enabled inside the trial.
- Further site settings, one `name=value` pair per line.
- Which allowlisted plugins are baked into the bundle at build time — the
  plugin allowlist gained a **Bake into bundle** checkbox per entry.

The page downloads a config file that drives `oer-sandbox`'s build scripts,
and computes a short stamp identifying the saved configuration. Once you tell
it the settings above are actually bundled in what's deployed, the page
fetches the deployed bundle's own stamp and warns if it no longer matches
what you have saved — so a rebuild you forgot to redeploy, or a redeploy of
an out-of-date bundle, doesn't go unnoticed.

This page requires a new capability, `local/oerexchange:managesandbox`,
granted to the **Manager** role only by default: what's configured here ships
into every trial, and a shipped grant cannot be corrected by a later redeploy.

## Fixed (found by live verification of the above, before release)

- The sandbox configuration page fatally errored for every user (a missing
  require of `adminlib.php`). Fixing that exposed a second bug: all of this
  plugin's admin pages were registered only under `moodle/site:config`, so a
  manager holding solely this plugin's own capabilities could never reach any
  of them at all.
- A trial launched in Japanese by an English-speaking admin using the baked
  language-pack path silently booted in English: the pack copied correctly,
  but the step that should have set the trial's default language failed
  silently. Fixed, with the step now reporting its own outcome instead of
  going quiet on failure.
- Fetching the deployed bundle's stamp for the comparison above was blocked by
  Moodle's outbound-request security guard whenever the sandbox base URL
  resolves to a private address. New opt-in **Allow an insecure sandbox base
  URL** setting (`sandboxbaseurlinsecure`, off by default) relaxes that guard
  and TLS verification for that one fetch only, for a self-hosted sandbox on a
  private network or behind a self-signed certificate.

## Also in this release

- A handful of displayed strings ("License" → "Licence") now use
  International English spelling, matching Moodle core's own convention for
  user-facing prose. No string keys or Japanese strings changed.

No database changes beyond the `bake` column already shipped ahead of this
release (`local_oerexchange_pluginallowlist.bake`). No action is required
after upgrading beyond the usual `admin/cli/upgrade.php`.
