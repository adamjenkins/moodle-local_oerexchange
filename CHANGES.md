# Release notes — 1.2.1

- Offering the plugins of one Moodle version on another (Sandbox plugin allowlist, "Copy the
  plugins listed for", "See what would be added") now runs in the background. Each plugin is a
  plugins-directory lookup plus a download, a few seconds apiece, and the page used to stay
  blank until every one had finished. It now shows a progress panel naming each plugin as it is
  looked up, reloads into the usual preview when the plan is ready, and can be cancelled. It
  runs as an ad hoc task, so it needs cron; while it waits in the queue, site administrators get
  a "Run now" button.
- A curator's note on a collection item is no longer set in italics: it is upright, labelled
  "Curator's note:" and set off by a rule.
- Status badges (tags, collection visibility, "Only in collections", moderation states,
  allowlist branches) are readable: several failed WCAG AA contrast, down to 1.49:1. They now
  take their text colour from the theme (`text-bg-*`), at least 4.58:1 in Boost.
- Small warning text (for example "Added for a Moodle version this plugin does not claim to
  support" and "no longer available") was orange on white at about 1.9:1; it is now dark amber
  (`text-warning-emphasis`), 8.87:1.
- The camp listing (`.camp/listing.yml`) now carries screenshots with captions. They are
  excluded from the plugin ZIP.

No database or capability changes. One new ad hoc task,
`\local_oerexchange\task\rollover_preview`, and new language strings.
