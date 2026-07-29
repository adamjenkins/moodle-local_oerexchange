# Release notes — 1.0.1

No change to the plugin itself. This release exists to fix release
publication to the camp registry: the previous release workflow pinned
camp-tools v0.2.25, whose index-entry schema predates the `source-repo-id`
field the registry added to every claimed entry on 2026-07-28 (OIDC trusted
publishing), so publication of v1.0.0 could not succeed. The workflow is
replaced with the registry's current tokenless template (OIDC trusted
publishing, camp-tools v0.2.35); no access token, fork or repository secret
is needed any more.

The installable plugin code is identical to 1.0.0 apart from the version
metadata — the workflow file is excluded from the distribution ZIP.
