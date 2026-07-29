# Release notes — 1.0.0

The first stable release. The plugin is declared `MATURITY_STABLE`: the
catalogue, the identity handshake, the publish/import web services, author and
co-author controls, moderation, educator profiles and the sandbox integration
have all been exercised end to end against a real client site, and their
interfaces are now considered settled.

Two changes land with it.

**An admin now chooses which licences sharing may use.** A new *Allowed
licences* setting lists the site's enabled licences and defaults to the six
Creative Commons 4.0 ones. The choice is enforced in
`resource_manager::publish()`, so it covers every publish path — both
direct-upload forms and the `publish_resource` web service a client site
calls — and it is advertised to client sites through `get_config`'s
`acceptedlicenses`, which previously reported every licence the site knew,
including disabled ones. The two upload forms now offer that list as a
dropdown instead of a free-text field, preselecting the sharer's remembered
licence, then the site default, then CC BY-SA. Enforcement applies to **new**
resources only: an update or file replacement carries its stored licence
through, so tightening the list never strands something already published.

**Catalogue thumbnails have a fallback.** A resource with no cover image now
gets a neutral panel of the same size rather than no thumbnail, so lists and
cards stay aligned, and `search`/`get_resource` expose `coverimageurl` so
client sites can show the same covers.

Because the plugin now writes core's `filepicker_recentlicense` user
preference — which no core privacy provider declares — the privacy provider
gained a `user_preference_provider` declaration and export for it.

**A rejected upload no longer fails silently, and is no longer kept.** A live
privacy verification of the whole platform found that the sanity check did its
job — a backup carrying user data never reaches the catalogue — but that the
person who made the mistake was never told. The reason was recorded on the
version row and rendered only in the moderation queue, so an author saw an
upload that stayed "Pending" forever with no way to learn why, and no way to
act on the advice the message itself contained. Worse, the rejected file was
retained indefinitely, leaving exactly the student data the check exists to
keep out sitting in the Exchange's file storage.

Now: the reason appears on the author's own resource page (theirs and their
co-authors', for the newest upload, so a rejected *replacement* is reported
too); a backup refused for carrying user data is **deleted**, and the stored
reason says so; and `get_share_status` reports the newest upload's state and
its rejection reason, so a client site can tell its teacher rather than
showing a share as published that the Exchange has actually refused. A
backup that is merely corrupt is still kept, deliberately — it holds nothing
that needs minimising, and keeping it is what lets a moderator diagnose it.

Verified before release: 313 PHPUnit tests green, phpcs clean, and a live
end-to-end run on a real two-site deployment confirming that a shared course
reaches the Exchange carrying no user data at all, and that a backup exported
*with* user data is refused on every publish path.
