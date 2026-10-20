# Release notes — 1.2.0

## Download a whole collection

A collection can now be downloaded as one zip file: every resource in it, in
the collection's order, plus a `collection.txt` listing each item's title,
author, licence, link and the curator's note, so the licence terms travel
with the files.

The zips are built in the background, when the site is quiet, and a
collection's download is only offered while its zip is up to date; after a
change the page says the download is being prepared. Two new settings under
Site administration, OER Exchange, "Collections": the largest combined size
of a collection download (default 100 MB; 0 switches whole-collection
downloads off), and how many active users count as "busy" for building them.

## Show a resource only inside its collections

A resource's authors can choose to show it only as part of the collections
it is in. It then leaves the catalogue, search results (including client
sites' searches), profile pages and the browse block, while staying in its
collections; its own page says which collections it belongs to. This only
applies while it is in at least one public collection, so a resource can
never become reachable from nowhere.

## Add and remove in bulk

On a collection's page, tick several search results to add them at once, or
tick resources in the collection to remove them at once.

## Coloured badges

Courses, activities, data resources and collections now carry coloured
badges on catalogue cards, collection pages, profiles and resource pages.
The colours are set under Site administration, OER Exchange, "Badge
colours"; the badge text is black or white, whichever is easier to read.

## Sharing

- A new "Share buttons" setting shows the share buttons open, collapsed behind
  a "Share" button (as before, and still the default), or not at all.
- Each share button now appears in its network's own colour.
- The Mastodon button asks for your server once, then shares straight there
  and shows the server's name; "Change server" picks another. Signed-in users'
  choice follows them to other devices.

## Upgrading

The upgrade adds one column to the resources table; run it from Site
administration, Notifications, as usual. If you use the browse block
(`block_oerexchangebrowse`), upgrade it to 1.0.6 as well.
