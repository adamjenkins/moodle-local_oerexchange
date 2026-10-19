# Release notes — 1.1.0

## Collections

Educators can now build **collections**: ordered lists of resources from the
catalogue, their own or anyone else's, with a note on each one saying why it is
there, and a page of their own to share.

- **A page per collection** at `/local_oerexchange/c/{name}`, with a cover
  image (uploaded, or generated automatically from the first four resources'
  thumbnails), a description, the resources in order, and the same share
  buttons the resource pages have.
- **Draft, unlisted or public.** A new collection is a draft that only its
  owner can see. Unlisted collections can be seen by anyone with the link but
  are listed nowhere and ask search engines not to index them. Public ones
  appear everywhere below.
- **Adding resources**: an "Add to collection" control inside every resource
  page's "Share this resource" section, and a search box on the collection page
  itself for adding several at once.
- **Working together**: an owner can name other people as curators, who can
  add, remove, reorder and annotate resources but cannot rename, publish or
  delete the collection. An owner can also open a collection to contributions
  from anyone signed in; contributors can remove only what they added, and the
  owner is notified of every addition.
- **Where they appear**: a new **Collections** page listing public collections
  (newest, most starred or largest first, as cards or a list); the catalogue's
  search results, where collections now appear among resources (filter by type
  "Collection" to see only them); and each educator's profile. On their own
  profile, educators see all of their collections, including drafts, with a
  button to create a new one.
- **The front page**: an administrator can feature chosen collections above,
  below or instead of the catalogue list (Site administration, OER Exchange,
  "Collections"). In "instead" mode a link to the full catalogue is always
  shown.
- **Stars, reports and moderation**: collections can be starred and reported
  like resources, and a moderator can take one down and restore it. The
  moderation queue and the hidden-content report list collections alongside
  resources.

A collection whose resources are later hidden or removed keeps them: they
disappear from the public page, and its curators see how many are unavailable,
so that a resource hidden for a week does not destroy someone's work.

## Link previews

Resource pages and collection pages now produce a proper preview card when
their link is pasted into social media or a messaging app (Open Graph and
Twitter Card tags), as educator profiles already did. Profiles now carry the
Twitter Card tags too.

## Security fix

A resource's thumbnail upload checked only the first file in the upload area.
By placing a genuine image in a sub-folder of the upload area alongside another
file, a signed-in author could have stored a file that was not an image, which
the Exchange would then have served from its own address. Every file in the
upload area is now checked, and the Exchange now refuses to serve anything but
an image from its thumbnail areas, which also neutralises any such file a site
may already hold. Upgrading is recommended.

## Upgrading

The upgrade adds three tables and changes the reports table; run it from
Site administration, Notifications, as usual. Collections are switched on by
default (Site administration, OER Exchange, "Collections"). Creating a
collection needs the new `local/oerexchange:createcollection` capability,
given to the Authenticated user role by default; remove it from that role if
you do not want every signed-in user to be able to create one.

If you use the moderation-queue block (`block_oerexchangemodqueue`), upgrade it
to 1.0.8 as well.
