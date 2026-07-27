# Release notes — 0.1.7

A single fix, from the pre-submission security self-audit of 0.1.6.

Deleting a resource was gated only on "can you edit this?", and never looked
at whether a moderator was holding it. Because deletion also removes the
resource's moderation reports and flips it to `deleted`, the subject of a
copyright or spam complaint could delete the complaint against them **and**
the record that a takedown had ever happened — the entry then disappeared
from both of the moderation queue's lists. Co-authorship widened this: the
author of a resource under moderation could add co-authors to it, and each
of them inherited the same ability.

Authors and co-authors can no longer delete a resource whose status is
`modhidden` or `removed`; the button is replaced by an explanation of why.
Every other author control is untouched, moderators can still delete, and
GDPR erasure is deliberately unaffected — a person's right to have their
data removed is not suspended by their content being under moderation.
