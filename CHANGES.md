# Release notes — 1.2.3

Security release.

- Guests can no longer publish or edit. The share upload pages and the resource edit page sent
  guests through on sites with guest access, and every guest shares one account, so a guest
  could publish a data resource straight into the catalogue and edit what any other guest had
  shared. The pages now send guests to the login page, and publishing and editing refuse the
  guest account themselves.
- Linking a client site account now asks for consent. The connect page issued a permanent
  personal web service token on a plain page load and accepted any callback on the registered
  host. It now asks the user to confirm, issues a one-time code only on a confirmed form post,
  and accepts only the registered site's own local_oerclient callback (same scheme, host and
  port). The token is created when the client collects the code, so abandoned attempts leave no
  token behind, and a code can be collected only once even under concurrent requests.
- Revoking a client site now also deletes the personal tokens its account links created. (Tokens
  linked before this release are not recorded against a site and are not affected.)
- Co-authors and curators are now invited, not added. The person named receives an invitation
  and gets rights, attribution and a public profile only when they accept it. The notice the
  inviter sees is the same whether or not an account matched and never shows the person's
  name. Email addresses match only for users who may see them (email listed in "Show user
  identity" and the moodle/site:viewuseridentity capability); otherwise names are matched by
  username. Invitations are limited per inviter per hour, the same person cannot be invited to
  the same item again within a day, and unanswered invitations expire after 14 days.
- A new hourly scheduled task, "Clean up expired link codes, registrations and invitations", removes expired link codes (and any token
  an old code still holds), expired invitations and stale pending site registrations.
- Site registration is now also limited per network address (stored as a keyed hash, never the
  address itself). A user can have one open report per resource or collection. Import counts
  and import notifications are recorded only for published resources, and a repeat import of the
  same version from the same site and teacher within a day is not counted again.
- Cover images taken from an uploaded course backup can no longer be read from outside the
  backup's own files.
- "Adapted from" on a resource page names the original only when the viewer may see it, and a
  shared resource can only record an original its publisher may see.
- Publishing refuses an upload area holding more than one file.
- The collection delete confirmation shows the collection title escaped.

Database changes: new table `local_oerexchange_invitations`; new fields
`local_oerexchange_linkcodes.tokenid` and `local_oerexchange_sites.ipkey`. Existing co-authors and
curators are kept as they are. No capability changes.
