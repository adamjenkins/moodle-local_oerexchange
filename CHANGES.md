# Release notes — 0.1.5

Review-round hardening on the heels of 0.1.4.

Privacy completeness: the registered-sites table (whose contact column is a
person's email) is now declared in the privacy metadata, and an approved
"delete all users in context" request now actually deletes every user-keyed
row and tombstones every attributed resource — it was previously a total
no-op. The catalogue's tombstone skeleton still survives so inbound links
degrade instead of breaking.

Moderation correctness: a takedown can only be applied to a resource that is
actually up (published, author-hidden or pending) — a deleted tombstone can
no longer be pulled into the takedown/restore cycle and "restored" as an
empty husk, and Restore refuses politely when a resource has no validated
file to serve. The automatic abandoned-courseware removal now also backs off
if the author's account was deleted during the grace period: authorless
removal is a human moderator's call, at warning time and at removal time.

Input hardening: licenses are validated against core's license manager and
titles server-side on every publish path; the data-upload MIME sniff is per
extension (a flat list let any binary pass as .pdf); registration length
checks are multibyte-aware and cover all fields; account-link codes are
claimed atomically; expired-session posts now say so instead of silently
discarding the user's text. Plus: authors see their own hidden resources'
cover images again, two hard-coded English strings are translatable, and
the upgrade path no longer calls the plugin's own API.
