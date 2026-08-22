# Release notes — 1.0.10

The Exchange now has a public contributors listing, and co-authors count as
contributors everywhere rather than only where they could already edit.

## Contributors listing

A new public page at `/local_oerexchange/contributors` shows everyone with
something currently published, as cards: profile picture, name, any badges
they hold, up to three expertise tags, how many resources and courses they
have shared, and how recently. The whole card links to that contributor's
profile, using a single stretched anchor so assistive technology announces the
contributor's name rather than the card's entire contents.

A sort control offers most resources shared, most courses shared and recently
shared; a view control switches between cards and a compact list. Both
re-render in place over AJAX and still work with JavaScript turned off, where
they fall back to an ordinary form submission.

Only people who have left their profile publicly visible are listed, and only
those with at least one published resource — the visibility rule is enforced in
SQL, not in the renderer.

The companion `block_oerexchangecontributors` renders the same listing on the
Dashboard and front page through the same class, so the two surfaces cannot
drift apart.

## Co-authors are contributors

A co-author holds exactly the same rights over a resource as its creator, so
they now earn the same recognition:

- `profile_manager::get_metrics()` counts co-authored resources, so the profile
  page and the contributors listing report the same numbers.
- The nightly badge task evaluates co-authors, not only creators — previously a
  co-author who had created nothing was never assessed for Trusted Contributor
  at all.
- Being added as a co-author now creates that person's profile, the same way
  publishing does. Existing co-authors are backfilled on upgrade.
- Because that gives someone a public profile through another person's action,
  the "you were added as a co-author" notification now says so and points at
  the setting to turn it off.

## Fixed

- The star button on a resource page worked exactly once per page load and then
  stayed disabled. `core/ajax` returns a jQuery Deferred, and jQuery 3.7.1
  promises have `then`/`catch`/`always` but no `finally`; chaining `.finally()`
  threw a `TypeError` as the chain was built, after the button had been
  disabled, so nothing ever re-enabled it. Present since 1.0.9.

## Also

- `resource_manager::STATUS_PUBLISHED` replaces the bare `'published'` literal
  for the catalogue-visibility test.
- `badge_manager::get_badges_for_users()` is a batch lookup, so a listing does
  not run one query per row.

## Verified for this release

- PHPUnit: 653 tests, 1824 assertions, green.
- `scripts/phpcs-ci` (CI-equivalent, component-aware): clean, exit 0.
- `scripts/verify-gates`: all local gates fire on known-bad input, so those
  clean results are meaningful.
- The listing, both sort orders and both views exercised in a real browser
  against the test site.
- The cross-database portability of the new aggregate query is proven on
  MariaDB 11.8.6 only; the PostgreSQL leg runs in GitHub Actions.
