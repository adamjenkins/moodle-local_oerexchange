# Changelog

All notable changes to this project are documented in this file, in
[Keep a Changelog](https://keepachangelog.com/) format.

## [1.0.7] - 2026-08-03

### Added

- Authors and co-authors can edit a shared resource's details — new
  `edit_resource.php` plus `\local_oerexchange\form\edit_resource_form`,
  reached from an "Edit details" button beside "Replace the file". Covers
  title, description, subject tags and the thumbnail. Licence is deliberately
  excluded (it governs the terms people have already imported under), as are
  type/activitytype/courseformat (read from the package by `parse_backup_task`,
  not typed by anyone).
- The description is a rich-text field, backed by a new
  `resources.summaryformat` column backfilled to `FORMAT_HTML`. It is
  normalised back to HTML on save: the summary is also read by
  `block_oerexchangebrowse` straight from the table and by client sites over a
  service that carries no format field, so a non-HTML format would render
  correctly on the resource page and wrongly in three other places.
- Starring, on top of core's favourites subsystem (component
  `local_oerexchange`, itemtype `resource`, system context) rather than a new
  table — it brings its own unique index, so a double-click cannot double
  insert, and its own privacy provider. New AJAX-only external function
  `local_oerexchange_set_resource_star` and AMD module
  `local_oerexchange/star`, with a plain-link fallback that works without
  JavaScript. Starred resources appear under "Liked resources" on the
  starrer's educator profile, published resources only, since that page is
  world-readable.
- Moderator-only report of held resources (`moderate_hidden.php`), registered
  as an `admin_externalpage` gated on `local/oerexchange:moderate` and linked
  from `block_oerexchangemodqueue` with a count. New
  `resources.modhiddentime`, `modhiddenby` and `modhiddenversionid` columns
  record each takedown, and a new `local_oerexchange_modnotes` table holds one
  shared note per resource. Change detection anchors on the served version id
  rather than `timemodified`, which neither moves for a cover-image or
  co-author edit nor stays put for a hide.
- Moderator "Take down" and "Restore" controls on the resource page. The only
  takedown link previously sat on an open-report row, so a resource nobody had
  reported could not be taken down at all.
- Admin-editable content area at the top of the catalogue — a
  `catalogueintroenabled` checkbox plus a `catalogueintro` HTML editor,
  matching core's own `searchbanner` pair. Rendered inside
  `catalogue_view::render()`, so it reaches the plugin's own page and both
  site-home paths.

### Fixed

- **A moderator hiding somebody else's resource wrote the AUTHOR's `hidden`
  status**, which that author could switch straight back and which no
  moderation report listed. `user_can_edit_resource()` grants moderators every
  author control, including the author's own visibility switch, so the
  `modhidden`/`hidden` split that exists to stop an author undoing a moderator
  was being bypassed by that button. The hide/show action now requires the new
  `resource_manager::user_is_author()` (creator or co-author, without the
  moderator fallback), enforced in the action handler rather than only in the
  markup.
- The resource-type badge on an educator profile was a two-way ternary over a
  three-value column, so every `data` resource — and anything added later —
  was labelled "Course". One definition now, `resource_type::label()`, shared
  with the catalogue.
- Public custom profile fields ran through a bare `clean_text()`, which escapes
  but applies no text filter, so a URL in a profile field stayed dead text
  while the same URL in a bio was auto-linked. Now `format_text()`, which
  supplies the `originalformat` option `filter_urltolink` requires. Cleaning is
  unchanged, so the `javascript:`-href protection is intact and separately
  tested.
- Moderator names on the held-resources report are escaped at the sink:
  `fullname()` returns the stored name raw, `get_string()` does not escape
  placeholders and `html_writer::tag()` does not escape contents.
- `cover_image::save_from_draft()` checks the file size before reading the
  bytes, and reports whether a cover image actually exists afterwards rather
  than what was intended.
- `local_oerexchange_set_resource_star` refuses an unknown resource id and an
  unviewable one identically, so the refusal cannot be used to probe which ids
  exist.
- Two `@param` docblocks used shape/generic types containing a space
  (`array{…}`, `array<string, mixed>`), which makes moodlecheck read the type
  where the parameter name belongs and fail the phpdoc gate.

### Changed

- Restoring a held resource is one routine shared by `moderate.php` and the
  resource page, instead of two copies.
- Removed the now-unused `editthumbnail`, `thumbnailuploaded` and
  `error_thumbnailnofile` strings; `error_thumbnailtoolarge` takes the limit as
  a placeholder instead of hardcoding "5MB".

## [1.0.6] - 2026-08-02

### Added

- Allowlist entries can be deleted, not just disabled — `ingestor::delete()`
  plus a confirmation step on `manage_allowlist.php`. Removes the row and its
  mirrored ZIP; dependents are kept with their `parentid` cleared rather than
  cascade-deleted, since a dependency may be shared, and the confirmation
  reports how many exist. The mutating step uses `require_sesskey()` rather
  than `confirm_sesskey()`, which returns false instead of throwing and would
  have sent a stale request silently back to the confirmation screen.

### Fixed

- `db/install.xml` declared the `component` column added in 1.0.5 as
  `NOTNULL="true" DEFAULT=""`. XMLDB rejects an empty-string default on a CHAR
  NOT NULL column, fixes it itself, and reports via `debugging()` — so the
  schema was never wrong, but the message fails any moodle-plugin-ci run,
  taking the install step and every step after it with it. Declared without a
  default now, matching its neighbours in the same table; `db/upgrade.php`'s
  `add_field` matches, as core does for this case. No version bump was needed
  for the schema itself, only for the release.

## [1.0.5] - 2026-08-02

### Added

- The upload pages now show a real progress bar while a backup is being sent,
  with a percentage, a distinct "validating" stage once the last byte has left
  the browser, and the Share button disabled for the duration. Course backups
  here reach hundreds of megabytes (the largest on the live Exchange is 359 MB)
  and a plain multipart POST reports nothing at all to the user, so the page
  simply sat there for minutes. Progressive enhancement: the form posts to the
  same URL with the same sesskey and still works with JavaScript disabled.
- After an upload, the resource page tells the author what is happening to it:
  "Checking your upload…", then either "Published" or the rejection reason,
  without reloading. Publishing is asynchronous (a backup is validated by an
  adhoc task on the next cron run), and there was previously nothing to say so.
  Backed by a new AJAX-only external function,
  `local_oerexchange_get_publish_status`, gated on the same
  author/co-author/moderator check as every other author-side action.
- `sandboxmaxbytes` (default 0, no cap): above it no "Try it" button is
  rendered and `sandbox_launch.php` refuses a direct hit, both explaining the
  size and the limit. Off by default deliberately — a large trial works, it is
  only slow, so an upgrading site keeps the button it already has.
- `maxbackupbytes` is now an admin setting instead of a hidden config, and the
  upload pages state it. The JavaScript refuses an over-limit file before
  sending it and, for a file that is acceptable but awkward for the sandbox,
  says so when it is chosen; `publish()` enforces the same number on every
  path regardless.
- Resource pages and catalogue cards show the file size, and a resource large
  enough to make an in-browser trial slow carries a warning next to "Try it"
  explaining what to expect and pointing at the download instead. Measured
  2026-08-02: a 359 MB backup does boot in the sandbox, but takes 4 min 15 s,
  of which 3 min 54 s is a download reporting no progress — above the sandbox
  engine's 50 MiB fast-path budget it falls back to an in-PHP download with no
  percentage. The threshold is the new `sandboxwarnbytes` setting, defaulting
  to that same 50 MiB; set it to 0 to never warn. Nothing is refused on size.

- The sandbox plugin allowlist now derives its own metadata. An admin supplies
  a plugin ZIP URL, a GitHub repository URL or an upload, and the plugin type,
  name, release, supported Moodle versions and dependencies are read from the
  package's own `version.php`; the resulting plan is previewed and confirmed
  before anything is written. Previously all of it was hand-typed, once per
  Moodle branch, with the ZIP repackaged by hand. New pipeline in
  `classes/local/allowlist/`, reusing `\core\update\validator` (the same
  validation Moodle's own ZIP installer performs) and `\core\update\api`
  (dependency-component lookup only). `$plugin->supported`,
  `$plugin->incompatible` and `$plugin->dependencies` are read by a new
  `version_php_scanner`, which tokenises rather than including the file —
  core's own parser reads none of the three
  (`lib/classes/update/validator.php:537-540`).
- One allowlist entry is now created per supported Moodle branch from a single
  submission. `$plugin->supported` is read as the two-element RANGE core
  defines it to be (`lib/classes/plugininfo/base.php:313-320`), falling back to
  `$plugin->requires` (flagged inferred) and then to every deployed branch
  (flagged unverified).
- Declared dependencies are resolved, downloaded, allowlisted as active, and
  recorded against the entry that pulled them in (new `parentid` column).
  Standard Moodle plugins are skipped; unresolvable ones are surfaced to the
  admin rather than dropped. The bake flag cascades from a plugin to its
  dependencies.
- Mirrored ZIPs are normalised on ingest so the archive's single root directory
  is always the plugin's own name, whatever the source archive called it.
- New `cli/add_allowlist_plugin.php` (`--url`/`--zip`, `--bake`, `--dry-run`)
  running the same pipeline as the admin page.
- Allowlist entries can be deleted, not just disabled — `ingestor::delete()`
  plus a confirmation step on `manage_allowlist.php`. Removes the row and its
  mirrored ZIP; dependents are kept with their `parentid` cleared rather than
  cascade-deleted, since a dependency may be shared. The confirmation reports
  how many dependents exist. The mutating step uses `require_sesskey()` rather
  than `confirm_sesskey()`, which returns false instead of throwing and would
  have sent a stale request silently back to the confirmation screen.
- An admin can now allowlist a plugin for Moodle versions it does not declare
  support for ("Add it for Moodle versions it does not claim to support", or
  `--ignore-supported`). A stale `$plugin->supported` range is common and
  otherwise makes a working plugin unaddable, since it maps to no deployed
  branch. Cascades to dependencies — a forced parent with an unforced
  dependency is still broken. Deliberately does **not** override
  `$plugin->incompatible`, which is a positive assertion of breakage rather
  than an omission. The confirmation page names the branches being added
  against the plugin's declaration, and each such row records
  `ingestor::NOTE_OVERRIDDEN` in `notes` so the entry stays explicable later;
  a refresh that no longer needs the override clears it.

### Changed

- `local_oerexchange_pluginallowlist` gains `component`, `parentid`,
  `pluginversion` and `pluginrelease`, and its `(plugintype, pluginname,
  moodlebranch)` index becomes UNIQUE so re-adding a plugin updates its entry
  instead of duplicating it. **The upgrade is destructive where duplicates
  exist**: it backfills `component`, then deletes every row of a duplicate set
  except the most recently added one, along with that row's mirrored ZIP,
  before adding the index. 1.0.4's index was NOTUNIQUE and its add handler
  inserted unconditionally, so duplicates are reachable through ordinary admin
  use. Disclosed at the top of CHANGES.md.
  The de-duplication reads its groups with `get_recordset_sql()`, not
  `get_records_sql()` — the latter keys on the first selected column, so two
  duplicate sets sharing a `plugintype` (`mod` twice, the ordinary case)
  collapsed into one and the survivor broke the UNIQUE index add. That happens
  before `upgrade_plugin_savepoint()`, so it aborted the whole site upgrade
  with no way forward on retry. Regression test:
  `tests/local/allowlist/upgrade_dedup_test.php`.
- The allowlist add form is now a `moodleform`, replacing direct `$_FILES`
  handling that validated neither size nor type and bypassed the File API.
- Removed the now-unused `allowlistplugintype`, `allowlistpluginname`,
  `allowlistsourceurl` and `allowlistsha256` strings (the fields they labelled
  no longer exist).
- The maximum accepted upload size has one definition,
  `size_advice::max_upload_bytes()`, replacing the copy of
  `get_config(...) ?: 500 * 1024 * 1024` that `resource_manager::publish()`,
  `external\get_config` and (now) the upload pages each carried. A site that
  changed the value could previously advertise one number to client sites and
  enforce another.

### Fixed

- `oer-sandbox`'s `bake.sh` and `build-bundle-with-plugins.sh` mapped a plugin
  type to its directory with a hardcoded `mod|block|local` case that aborted the
  bake on anything else. Now a shared `oer_plugin_type_dir()` in
  `scripts/common.sh` carrying Moodle's full plugin-type map, generated from
  `core_component::get_plugin_types()`. Automatic dependencies make a `qtype_`,
  `filter_` or `tool_` entry reachable without anyone typing it.
- Sharing a backup used to end on the catalogue with a notification reading
  just "Share" — the submit button's own label, passed to `redirect()` by
  mistake — and the new resource's id was discarded. Both upload pages now land
  on the new resource's own page with a message that says what happened, which
  is also the one page that can show a resource that is still pending.

## [1.0.4] - 2026-08-01

### Security

- Custom profile field values shown on the public profile page are passed
  through `clean_text()` before display. `profile_field_social::display_data()`
  substitutes the raw stored value into `<a href="%%PLAIN%%">` with no
  escaping, and the edit form's `PARAM_URL` is bypassed by every non-form
  writer (`core_user_create_users`/`update_users` declare
  `customfields[].value` as `PARAM_RAW`, as do uploaduser and LDAP/OAuth2
  sync). Core accepts that because `/user/profile.php` honours
  `$CFG->forceloginforprofiles`; this page is deliberately public. Applied to
  every field type rather than allowlisting known-safe ones, so third-party
  field types are covered and none is silently dropped.
- The catalogue's licence and language filter option *labels* are escaped with
  `s()`. `html_writer::select()` hands each label to `html_writer::tag()`
  (`lib/classes/output/html_writer.php:346`), whose content argument is not
  escaped — only the value attribute was being escaped. Not exploitable via
  the publish web service, which declares both `PARAM_TEXT`; applied at the
  sink so the value is neutralised however it reached the database. Predates
  this release; hardened now because the same filter row is served on the
  site front page by the new public landing page.
- The `.mbz` URL interpolated into the sandbox's generated PHP is escaped with
  `addcslashes($url, "'\\")` instead of escaping the quote alone, so a trailing
  backslash cannot break out of the generated string literal. Not reachable
  with current inputs; hardened because it is a generated-code boundary.

### Added

- Optional public landing page: a new `publiclanding` setting (off by default)
  serves the catalogue in place at the site root for visitors who are not
  logged in, via a `\core\hook\after_config` listener that runs before core
  `index.php`'s `require_course_login()`. `forcelogin` can stay on for the
  rest of the site. Rendered rather than redirected, so the address bar stays
  at the site root; there is consequently no redirect target to configure and
  no open-redirect surface. Guarded against non-front-page requests,
  logged-in (non-guest) users, `?redirect=0`, `$CFG->maintenance_enabled`,
  initial install and pending upgrades.
- The catalogue is offered as a "Default home page for users" option
  (Appearance → Navigation) via `\core_user\hook\extend_default_homepage`,
  covering logged-in users, whom the listener above deliberately leaves to
  core.
- A "Try it" trial enrols its own user in the trial course as both Editing
  teacher and Student, via the manual enrolment plugin, for full-course and
  single-activity trials alike.
- The public educator profile lists the site's additional user profile fields
  that the administrator has set to "Visible to everyone" and the user has
  filled in, rendered with each field's own display formatting (including a
  text field's configured link format).

### Changed

- Educator profile descriptions are rendered with `format_text()` in
  `FORMAT_MOODLE` instead of `FORMAT_PLAIN`, so site text filters — including
  auto-linking and multilang — apply. HTML cleaning remains on.
- The catalogue listing moved out of `index.php` into
  `\local_oerexchange\local\catalogue_view`, which takes its form action and
  paging base URL from the caller instead of hardcoding
  `/local/oerexchange/index.php`. No user-visible change to that page; it is
  what lets the same catalogue be served at the site root.
- Licence shortnames on the resource page and the catalogue cards are wrapped
  in `<span class="oer-licence-name">` by the new
  `\local_oerexchange\local\licence_display`, and shown in capitals by
  `styles.css` (the plugin's first stylesheet) rather than by transforming the
  text. A new **Show licence codes in capitals** setting
  (`uppercaselicencenames`, on by default) drops the modifier class; a theme
  can override `.oer-licence-name--upper` instead, since plugin sheets are
  emitted before theme CSS. The DOM text is now always the stored shortname,
  so copied text and assistive tech get the real identifier, and the licence
  filter — whose option labels are also its query values — keeps matching.

### Removed

- **Breaking:** the `orcidurl`, `linkedinurl` and `researchmapurl` columns are
  dropped from `local_oerexchange_profiles` and their stored values discarded.
  The corresponding profile-edit inputs, public-profile links and privacy
  metadata entries are gone. Superseded by the custom profile fields above.

### Fixed

- Resource titles, section titles, registering-site names, subject tags and
  author-supplied text on `resource.php`, `moderate.php`, `manage_sites.php`,
  `share_upload_mbz.php`, `share_upload_data.php` and the public profile page
  now pass through `format_string()`/`format_text()` rather than bare `s()`,
  so multilang markup is filtered instead of displayed literally.
- The Open Graph description no longer strips tags off unfiltered text.
- Resource titles in co-author and stale-resource notifications are filtered
  and flattened to plain text before being placed in a `FORMAT_PLAIN` message.
- Cover-image `alt` attributes no longer double-escape an ampersand: the
  filtered value is decoded before `html_writer` escapes it once.
- `s()` removed from the slug and expertise inputs' `value` attributes, which
  `html_writer` already escapes.

## [1.0.3] - 2026-08-01

### Fixed

- A "Try it" trial now installs every language pack the sandbox configuration
  names, not only the language the trial opens in. With a Japanese pack
  configured and an English-speaking visitor, the pack was baked into the
  bundle correctly and then never installed, leaving the trial with a single
  translation — and Moodle hides the language switcher below two. The trial
  language still decides only which of the installed languages it opens in.

## [1.0.2] - 2026-07-31

### Added

- Sandbox bundle configuration page (Site administration → Plugins → OER
  Exchange → Sandbox bundle configuration): language packs to bake in, the
  trial's default language, which Moodle branches to build, whether the
  multilang filter (content and, separately, headings/names) is enabled in
  the trial, further site settings as `name=value` pairs, and a per-entry
  **Bake into bundle** checkbox on the plugin allowlist. Generates a
  downloadable config file for `oer-sandbox`'s build scripts and a short
  stamp identifying the saved configuration.
- Deployed-bundle stamp check: once an admin confirms a configuration is
  actually bundled in the deployed sandbox, the page fetches the deployed
  bundle's own stamp and warns if it no longer matches the saved
  configuration.
- New capability `local/oerexchange:managesandbox` (Manager only by default)
  gating the new page.
- New opt-in setting `sandboxbaseurlinsecure` (off by default): relaxes core's
  outbound-request security guard and TLS verification for the deployed-stamp
  fetch only, for a self-hosted sandbox on a private network or behind a
  self-signed certificate.
- `local_oerexchange_pluginallowlist.bake` column, letting an admin mark an
  allowlisted plugin for baking into the bundle rather than installing it at
  trial boot.

### Fixed

- Resource titles, descriptions, catalogue-card summaries and structure-preview
  section/activity titles rendered multilang markup as literal text even with
  the multilang filter enabled — every sink used `s()` (escapes before any
  filter runs) or `format_text(..., FORMAT_PLAIN)` (which also escapes before
  filtering). Switched to `format_string()`/`format_text(FORMAT_HTML)` with a
  system context, matching `block_oerexchangeshares`'s already-correct
  pattern.
- The sandbox configuration page fatally errored for every user (missing
  `adminlib.php` require); fixing it exposed that all of this plugin's admin
  pages were reachable only by users holding `moodle/site:config`, locking out
  a manager with solely this plugin's own capabilities. All four admin pages
  are now registered independently of `$hassiteconfig`.
- A trial's baked-language-pack path silently failed to set the trial's
  default language, so a Japanese-launched trial still booted in English
  despite the pack installing correctly.
- International English spelling ("License" → "Licence") corrected in a
  handful of displayed strings.

## [1.0.1] - 2026-07-29

### Changed

- The camp release-publishing workflow now uses the registry's current
  tokenless template (OIDC trusted publishing, camp-tools v0.2.35). The
  previous template pinned camp-tools v0.2.25, whose index-entry schema
  predates the registry's `source-repo-id` field, so publication of v1.0.0
  could not succeed. No change to the plugin itself.

## [1.0.0] - 2026-07-29

First stable release. `$plugin->maturity` is now `MATURITY_STABLE`.

### Added

- **Allowed licences admin setting** (`allowedlicenses`). A multicheckbox of
  the site's *enabled* licences (`license_manager::get_active_licenses_as_array()`),
  defaulting to the six Creative Commons 4.0 licences, with the same strict
  `=== false` unset-vs-empty rule as `sharetargets` — unticking every box
  means "no licence may be chosen", not "restore the default".
- `local_oerexchange\local\allowed_licenses`: the one place that resolves the
  allowed list, the dropdown menu, and the preselected licence.
- Licence preselection, first-allowed-wins: the user's remembered
  `filepicker_recentlicense` (only under `$CFG->rememberuserlicensepref`),
  then `$CFG->sitedefaultlicense`, then CC BY-SA. A successful share writes
  the same preference back, so this plugin's forms and core's filepicker
  share one memory.
- `coverimageurl` on the `search` and `get_resource` web services, so client
  sites can render the same cover images the catalogue shows.
- A neutral default thumbnail panel for resources with no cover image, on
  `index.php` and everywhere `cover_image::listitem()`/`card()` is used, so
  lists and cards keep their alignment.
- The settings heading links out to `tool_licensemanager`'s page, built via
  `\tool_licensemanager\helper::get_licensemanager_url()`.

### Changed

- The licence field on `share_upload_mbz.php` and `share_upload_data.php` is a
  dropdown of exactly the allowed list; it was a free-text input, unlike the
  client's form.
- `get_config`'s `acceptedlicenses` advertises the allowed list. It previously
  returned `get_licenses()`, which included licences the site had *disabled*.

### Security

- `resource_manager::publish()` refuses a licence that is not on the allowed
  list (`error_licensenotallowed`, distinct from `error_invalidlicense`), so
  the `publish_resource` web-service path client sites use is covered and not
  just the interactive forms. **New resources only** — updates and file
  replacements carry the stored licence through, so tightening the list never
  strands an existing resource.

### Privacy

- The privacy provider now implements `user_preference_provider`, declaring
  and exporting `filepicker_recentlicense`. The plugin writes that core-owned
  preference and no core provider declares it.
- **A backup rejected for containing user data is now deleted, not retained.**
  `parse_backup_task::mark_failed()` takes a `$purgefile` flag, set only on
  the sanity-check path, and the stored reason tells the author the file is
  gone. Previously the upload stayed in the `resource` filearea indefinitely —
  moderator-readable, for a resource that could never be published — which
  left precisely the student data the check exists to exclude sitting on the
  Exchange. An ordinary parse failure (corrupt or unreadable archive) still
  keeps its file: nothing there needs minimising, and it is what lets a
  moderator diagnose the failure.

### Fixed

- **The author is told why an upload was rejected.** `versions.parseerror` was
  rendered only in `moderate.php`'s failed-parses list, so the one person who
  could act on it — the author or a co-author — saw nothing but "Pending" and
  could not learn that their backup had been refused. `resource.php` now shows
  the reason to anyone who may edit the resource. It reports the **newest**
  version rather than only a pending one, so a rejected "Replace the file" is
  surfaced too; that case previously left a published resource silently
  serving its old file.
- **A client site can discover a rejection.** `get_share_status` gained
  `versionstatus` and `versionerror` (both `VALUE_OPTIONAL`), reporting the
  newest upload's state and, when it failed, why. A publish is acknowledged
  before validation runs, so without these a client is told the share
  succeeded and can never find out otherwise.

## [0.1.7] - 2026-07-27

### Security

- **An author could delete a resource out from under a moderator.** The
  `deleteconfirm` action was gated on `user_can_edit_resource()` alone and
  never consulted `$resource->status`, while
  `profile_manager::delete_creator_resource()` deletes the resource's
  `local_oerexchange_reports` rows and sets status `deleted`. The subject of
  a complaint could therefore destroy the complaint and the takedown record
  together, after which the entry appeared in neither of `moderate.php`'s
  lists (open reports; `status IN (modhidden, removed)`). Pre-existing for
  the creator, and widened by 0.1.6: an author whose resource was under
  moderation could add co-authors to it, each inheriting the same ability.
  Found by `/moodle-shield-audit` (class 1b) against 0.1.6, and reproduced
  before fixing.
- New `resource_manager::user_can_delete_resource()` and
  `resource_manager::MODERATOR_HELD_STATUSES`. Authors and co-authors are
  refused on `modhidden`/`removed`; moderators are not. This is the
  delete-shaped half of the rule `set_hidden()` already enforced for
  visibility (2026-07-23 author-control round), which had covered hiding but
  not deletion.
- The Delete button is replaced by an explanation rather than silently
  omitted, and the action handler refuses a directly-POSTed
  `deleteconfirm` independently of the UI.
- **GDPR erasure is deliberately NOT routed through the new gate.**
  `delete_creator_resource()` itself is unguarded and the privacy provider
  keeps calling it directly, so a moderated resource is still tombstoned by
  an approved erasure request. Covered by its own test.

## [0.1.6] - 2026-07-27

### Added

- **Co-authors.** An author can add further authors to a resource they have
  shared, naming them by username or email address (both matched
  case-insensitively; username wins when a string is one person's username
  and another's email). New table `local_oerexchange_coauthors`
  (`resourceid`, `userid`, `addedby`, `timecreated`) with a unique
  `(resourceid, userid)` index.
- A co-author holds **full parity** with the creator — file replacement,
  thumbnail, hide/unhide, delete, Try-it opt-out, freshness confirmation,
  and the co-author list itself. `resource_manager::user_can_edit_resource()`
  is the single gate all of those already consulted, so admitting a
  co-author there admits them everywhere at once. The creator is never a
  co-author row, so nobody they add can remove them.
- Co-authors are shown publicly on the resource page ("With ..."), linked to
  their educator profiles where those are visible.
- New `coauthor` message provider: the person added is told, by name, which
  resource and who added them.
- New `classes/local/coauthor_manager.php` and 18 unit tests covering
  identifier resolution, the five refusal cases, parity, chain-adding,
  revocation and the GDPR paths.
- **Cover-image thumbnails** on `index.php` and in the `oerexchangebrowse`,
  `oerexchangequicklinks` and `oerexchangeshares` blocks, via new
  `classes/local/cover_image.php`. Resources with no cover get an
  equally sized neutral panel so grids and lists stay aligned; the URL
  lookup is a single batch query per page rather than one per item.
  The quick links block's thumbnail is also its first-ever route to the
  resource page.

### Security / Privacy

- `local_oerexchange_sites` was declared in the privacy metadata from 0.1.5
  but appeared in no export path, no delete path and neither discovery
  method. Both links that exist — `serviceuserid`, and `contact` matching a
  local user's email — are now serviced. Erasure **scrubs the contact and
  keeps the registration**: deleting the row would sever a live third-party
  integration whose service account and token remain valid.
- `db/uninstall.php` now deletes each registered site's service account and
  its web service tokens. Previously both survived the uninstall with the
  only record of which accounts were ours destroyed alongside the tables.
  Only an account whose username still matches the `oersite_<siteid>` form
  this plugin mints is deleted.
- `sandbox_launch.php` is gated on the `anonymousdownload` setting. It
  minted a 15-minute signed `.mbz` URL and base64'd it into the redirect's
  `blueprint` parameter, readable straight out of the `Location` header, so
  "Try it" was a two-step way around a gate that is off by default.
  **Behaviour change**: on a default configuration an anonymous visitor
  pressing Try it is now sent to the login page.
- `register.php` reuses an existing pending registration for the same URL
  (matched case-insensitively) and refuses beyond 30 new registrations per
  hour with a `429`. Sandbox trials collapse to one row per viewer per
  resource per five minutes. Both endpoints accept unauthenticated writes
  and were previously unbounded.
- `connect.php` compares the callback's scheme as well as its host
  (`link_manager::callback_matches_site()`), so an https-registered site can
  no longer be handed an `http://` callback carrying its one-time link code.
- Privacy provider services the new co-authors table in both directions: a
  user's own co-authorships are exported and erased, while grants they made
  to *other* people survive with the `addedby` attribution scrubbed to 0 —
  revoking a third party's editing rights is not part of this user's
  erasure. Tombstoning a resource drops its co-author rows.

### Changed

- `resource_manager::publish()`'s update branch uses the shared edit gate
  instead of a bare `creatorid` comparison. This is what lets a co-author
  replace the file, and incidentally settles a pre-existing inconsistency
  where both upload pages admitted a moderator only for `publish()` to
  reject them. Replacing the file still never transfers ownership.
- `get_share_status` (web service) uses the same shared gate, so a co-author
  polling status from their own client site sees the entry they can edit.
- The three Exchange blocks lay their rows out as thumbnail-left,
  text-right.

### Fixed

- The co-author notification's subject used the `{$a->title}` form while the
  caller passes a plain title, so it went out reading literally
  `You are now a co-author of "{$a->title}"`. Caught on the live site, not
  by the tests that existed at the time; there is now a test asserting no
  unsubstituted placeholder survives in either the subject or the body.
- A Behat scenario in `oerexchange_admin_settings.feature` had been asserting
  against **OER Client's** settings page: both plugins publish a page called
  "General settings" under Local plugins, and the single-path navigation step
  resolved to the wrong one without failing. It now navigates to the
  plugin's own category and follows the link from there.

## [0.1.5] - 2026-07-27

### Security / Privacy

- Privacy metadata now declares `local_oerexchange_sites` (its `contact`
  column is a person's email address, regardless of it mapping to no local
  account).
- `delete_data_for_all_users_in_context()` now deletes every user-keyed
  row and tombstones every attributed resource (it was an unconditional
  no-op, silently retaining profiles, badges, reviews and reports through
  an approved delete-all request). The tombstoned catalogue skeleton
  survives by design.
- Licence shortnames are validated against core's licence manager and
  titles are checked non-empty server-side on every publish path (WS,
  .mbz upload, data upload).
- The data-upload MIME sniff is per extension: the flat shared list let
  `application/octet-stream` neutralise the check for every type, so any
  binary renamed to `.pdf` passed.
- Account-link codes are claimed with an atomic conditional UPDATE —
  two concurrent requests could previously both consume the same code.
- `register.php` validates url/contact lengths (and name multibyte-aware)
  before insert instead of dying on a DB-level error.

### Fixed

- Moderation: hide/remove only applies to `published`/`hidden`/`pending`
  resources — a `deleted` tombstone could previously be taken down and
  then "restored" to published as a scrubbed, fileless husk. Restore now
  warns and refuses when the resource has no validated version to serve.
- The abandoned-courseware removal pass re-checks that the author is
  still reachable, matching the warning pass: an author deleted during
  the grace period leaves removal to a human moderator (new pinning test).
- Cover images follow the resource page's own access rule (creator or
  moderator for non-published) — authors saw a broken image on their own
  hidden/pending resources.
- Report/review/thumbnail/owner actions throw on a missing or expired
  sesskey instead of silently discarding the submitted text.
- The 2026072300 upgrade step no longer calls the plugin's own API
  (inlined with identical behaviour); `install.xml`'s VERSION attribute
  updated; a dead duplicate query removed from `get_resource`; thumbnail
  alt text no longer double-escapes; the badges task test no longer
  reports risky output; two hard-coded strings ('Error', '(deleted)') are
  now translated (EN+JA).

## [0.1.4] - 2026-07-27

### Added

- Abandoned-courseware lifecycle, off by default. Three new admin settings
  (enable, threshold — default 2 years, grace period — default 60 days), a
  nightly `check_stale_resources_task`, a `stale` message provider, and two
  new columns on the resources table (`timefresh`, `stalenotifiedtime`).
  A stale published resource's author is warned once; updating the resource
  or the one-click "Still fresh" button (resource-page banner, linked from
  the warning) resets the clock; otherwise the resource is removed when the
  grace period ends. Automatic removal writes the same restorable 'removed'
  status a moderator's takedown uses, and a moderator restore now also
  resets the freshness clock so a restored resource is not removed straight
  back. Resources with no reachable author are skipped entirely.
- Share buttons now show each destination's logo (brand icons for Mastodon,
  Facebook, X and LinkedIn) from Moodle's bundled FontAwesome via the
  standard icon-map callback. Icons are decorative and aria-hidden; labels
  are unchanged. On a theme whose icon system is not FontAwesome the buttons
  stay text-only.

## [0.1.3] - 2026-07-23

### Added

- A sandbox trial boots in the launching visitor's own interface language:
  the blueprint carries an `installLanguagePack` step for any non-English
  `current_language()`, installed with `setDefault` so the trial's
  auto-logged-in admin actually reads it. English emits no step at all.
  A language code that is not a Moodle language code is dropped rather than
  passed to the sandbox, which interpolates it into generated PHP and a
  dataroot path.

### Changed

- The blueprint's `login` step now follows the language step rather than
  preceding it. Installing a pack after login has no visible effect: Moodle
  serialises `$USER` into the session at login, so the language change lands
  too late for that session to read, and the trial renders in English
  despite a completely successful install.

## [0.1.2] - 2026-07-23

### Added

- **Moderated resources** section on the moderation queue, listing everything
  a moderator has hidden or removed, with a Restore action. Until now a
  moderator takedown could not be reversed from anywhere in the interface.
- **Replace the file** on a resource's owner card: re-upload a `.mbz` or data
  file for an already-published resource. Reuses the existing upload pages in
  an update mode, so file validation stays in one place. Metadata is not
  editable there — only the file changes — and the type is locked both ways.
- Japanese translations for the 82 strings that had fallen behind English
  (profiles, badges, thumbnails, data resources, privacy metadata).

### Changed

- A moderator hide now writes `modhidden` rather than `hidden`. `hidden` is
  the author's own switch; `modhidden` and `removed` are moderator states only
  a moderator can lift. The author can still view their own moderator-hidden
  resource and is told a moderator hid it.
- Test classes use PHPUnit attributes (`#[CoversClass]`, `#[DataProvider]`)
  instead of doc-comment metadata, which PHPUnit 11 deprecates and 12 drops.

### Fixed

- An author could un-hide a resource a moderator had hidden, silently undoing
  the takedown. Both actions wrote the same status.
- `trydisabledreason` is now declared in the privacy provider — it is
  author-written free text.

## [0.1.1] - 2026-07-23

### Added

- Share affordance on educator profile pages and resource pages, with an
  admin setting choosing which destinations are offered (copy link, native
  `navigator.share`, Mastodon, Facebook, X, LinkedIn, email, SMS). Every
  network target is a plain link to that network's own share endpoint; no
  third-party script, widget or tracking pixel is loaded. Adds the plugin's
  first AMD module, `local_oerexchange/share`.
- Authors can hide and show their own resources, and delete them (tombstone:
  files, versions, reviews and reports removed, row kept so existing links
  resolve to the "no longer available" message).
- Authors can turn off "Try it" for a resource and give an optional reason,
  which is shown in place of the button. `sandbox_launch.php` enforces it
  independently of the button being hidden.
- `local_oerexchange_get_share_status` web service: lets the client site
  that published a resource read back its status, first-published and
  last-updated times, and download/import counts. Answers only for the
  caller's own resources, so it can safely report hidden ones.

### Changed

- The Exchange now serves exactly one version per resource. Updating uploads
  a new version and, once it validates, supersedes the previous one —
  deleting its file while keeping its row so `imports.versionid` and
  `trials.versionid` never dangle. Superseding deliberately happens after
  validation, so a failed update leaves the previous good version serving.
- A profile's owner now sees their own hidden resources in their profile
  listing, flagged as hidden.

### Fixed

- The "Share my profile" button did nothing visible. It tried
  `navigator.share`, fell back to `navigator.clipboard.writeText`, and gave
  no feedback on any path, swallowing the clipboard rejection in an empty
  catch. `navigator.share` is undefined on most desktop browsers, so the
  realistic path was a clipboard write that either succeeded or was denied,
  silently, in both cases looking exactly like a dead button.
- Viewing a non-published resource was gated on the moderator capability
  alone, so an author who hid their own resource was locked out of the page
  that could unhide it.

## [0.1.0] - 2026-07-18

### Added

- Catalogue: publish, browse, search, resource detail page with structure
  preview.
- Identity: client-site registration + admin approval (dedicated non-login
  service account, real WS token as the "site key"), personal
  account-linking handshake (`connect.php`, one-time link codes).
- Web services: `search`, `get_resource`, `publish_resource`,
  `record_import`, `get_config`.
- `.mbz` structure-preview parser (`mbz_parser`) with required-plugins
  derivation against core's standard-plugins list.
- Server-side sanity check rejecting backups containing user data.
- Reviews (adaptation stories), reports, and a moderation queue.
- Sandbox plugin allowlist (curated contrib plugins, same-origin mirrored
  ZIPs) and Moodle Playground trial-launch integration — no server-side
  trial execution, no Podman fleet.
- HMAC-signed short-lived download URLs for both WS clients and sandbox
  trials.
- GDPR privacy provider.
