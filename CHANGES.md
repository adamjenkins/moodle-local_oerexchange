# Release notes — Unreleased

- The Japanese language pack (lang/ja) is no longer included: releases ship the English strings
  only, as the Moodle Plugins directory expects. Japanese is provided through Moodle's language
  packs.

# Release notes — 1.0.12

Declare Moodle 5.3 support. The plugin now declares itself supported on
Moodle 5.0 through 5.3, and its `composer.json` admits Moodle 5.3 too
(`>=5.0 <5.4`), so the plugin can be installed with Composer on a 5.3 site.

On Moodle 5.3, where `user_create_user()` is deprecated, the service accounts
for registered sites are created with `\core\user::create_user()` instead.
Earlier Moodle versions keep using `user_create_user()`.

No database or capability changes. No action is required after upgrading.
