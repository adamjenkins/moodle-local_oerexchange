# Release notes — 1.0.4

## Multilingual titles and descriptions now display correctly

If your site uses the multilang filter to publish bilingual content, resource
titles and descriptions on several pages showed the raw
`<span lang="en" class="multilang">…</span>` markup as visible text instead of
the language the visitor is reading in. The cause was the same everywhere:
those values were HTML-escaped for safety but never passed through Moodle's
text filters. Fixed on the resource page, the moderation pages, the registered
sites page, the upload pages, the public educator profile, and the Open Graph
description used for link previews.

**Site requirement:** for titles and other short strings, Moodle only runs the
multilang filter when that filter is set to apply to *content and headings*
rather than content alone (Site administration → Plugins → Filters → Manage
filters). Long descriptions are filtered either way. No plugin can change this;
if titles still show markup after upgrading, that setting is why.

## Text filters now apply to educator profile descriptions

A profile description was previously rendered as plain text, so a URL in it
stayed a bare URL and no filter ran. Descriptions now go through Moodle's
standard text formatting, so auto-linking, multilang and any other filter your
site enables all work — with Moodle's HTML cleaning applied, as everywhere else.

## Educator profiles show your site's own profile fields

The three fixed "ORCID URL", "LinkedIn URL" and "ResearchMap URL" boxes have
been removed. In their place, a public profile now lists whichever of the
site's **additional user profile fields** (Site administration → Users →
User profile fields) the administrator has set to **"Visible to everyone"**,
and which the user has filled in. Fields set to any other visibility, and
fields the user left blank, do not appear.

This puts the choice of what a profile can advertise in the administrator's
hands: an ORCID field, a personal site, a department, a mentoring flag —
whatever suits your community — including fields configured to display as
links, which render as links here.

**This upgrade permanently deletes the three old columns and any URLs stored
in them.** There is no migration: if those values matter to you, export them
before upgrading. Re-create them afterwards as additional user profile fields
if you want them back.

## "Try it" trials now enrol you in the trial course

A trial previously left you as a site administrator who was not a member of
the course you had come to look at, so the Participants list was empty and
anything that depends on being enrolled — the gradebook, activity completion,
switching role to Student — behaved oddly or not at all. A trial now enrols
its own user in the trial course as both **Editing teacher** and **Student**,
through the manual enrolment plugin, for both full-course and single-activity
trials.

## The catalogue can now be your site's front page for visitors

If your Exchange has **"Force users to log in"** switched on, someone arriving
at your site's home page was sent straight to the login form — so the
catalogue, the whole point of a public Exchange, was invisible to anyone
without an account. Browsing the catalogue itself has never required logging
in; only the front page stood in the way.

A new setting, **"Show the catalogue to visitors at the site home page"**
(Site administration → Plugins → Local plugins → OER Exchange), changes that.
With it on, a visitor who is not logged in and opens your site's home page is
shown the catalogue **at that address** — the page is served in place, so the
address bar stays on your site's home page rather than jumping to a longer
URL, and searching from there keeps them there.

**It opens one door, not the site.** "Force users to log in" still applies to
everything else: courses, dashboards, administration and the rest all behave
exactly as before. The setting is **off by default**, so upgrading changes
nothing until you deliberately turn it on. Administrators can always reach the
normal front page at `/?redirect=0`. Guests are treated as visitors and see
the catalogue too, and the catalogue is not served while the site is in
maintenance mode.

Logged-in users are not affected by this setting. To send them to the
catalogue as well, this release also adds an **"OER catalogue"** option to
Moodle's own *Site administration → Appearance → Navigation → Default home
page for users*, which you can also let users choose individually. Note the
two work slightly differently, because Moodle handles the logged-in case
itself: a logged-in user is taken to the catalogue's own address, whereas a
visitor sees it at the site home page address.

## Licence codes are shown consistently, and you can choose the style

A resource's licence code is now displayed the same way everywhere — on
resource pages, catalogue cards, the browse block, and on connected client
sites. Previously the client plugin showed `CC-SA-4.0` while this site showed
`cc-sa-4.0` for the same resource.

A new setting, **Show licence codes in capitals**, chooses between the two; it
is on by default, so codes appear as `CC-SA-4.0`. This affects appearance only:
the licence is stored, filtered and sent to client sites exactly as it was
published, so text you copy from a page still matches, and screen readers read
the code out rather than spelling out capital letters. The licence filter
dropdown keeps the stored spelling either way. If you would rather style this
in your theme, the codes are wrapped in `.oer-licence-name`.

## Security

Because the profile page is public, the values of the custom profile fields it
shows are now passed through Moodle's HTML cleaner before display. Moodle's
"social" profile field type places a stored value directly into a link's
address without escaping it, and the checks on that value are bypassed when a
profile is written by a web service, a bulk user upload, or LDAP/OAuth2
directory sync rather than by the profile form. Core tolerates this because
its own profile pages can be hidden from anonymous visitors; this page cannot.
Legitimate links, dates and checkbox values are unaffected.

## Also fixed

- A registering site's name is filtered on the moderation and site-management
  pages.
- Resource titles in co-author and stale-resource notification messages no
  longer contain multilang markup.
- A cover image's alternative text no longer double-escapes an ampersand in
  the title.

Beyond the usual `admin/cli/upgrade.php`, no action is required after
upgrading — but note the permanent data removal described above.
