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

Verified before release: 306 PHPUnit tests green, both Behat scenarios green,
phpcs and moodlecheck clean, and a live end-to-end run on a real two-site
deployment confirming that a shared course reaches the Exchange carrying no
user data at all, and that a backup exported *with* user data is refused on
every publish path.
