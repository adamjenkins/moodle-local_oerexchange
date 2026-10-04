# Release notes — 1.0.13

The Japanese language pack (lang/ja) is no longer included: releases ship the
English strings only, as the Moodle Plugins directory expects. Japanese is
provided through Moodle's language packs.

The Composer package now accepts later Moodle 5.x releases
(`moodle/moodle` `^5.0`) instead of stopping before 5.4. The supported
versions declared in `version.php` are unchanged (Moodle 5.0 to 5.3).

The automated tests now run against the released Moodle 5.3
(`MOODLE_503_STABLE`) instead of Moodle's development branch, and those runs
now count towards a pass.

No database or capability changes. No action is required after upgrading.
