@local @local_oerexchange
Feature: Collections of OER resources
  In order to point colleagues and students at a reading list rather than at single resources
  As a teacher sharing on the Exchange
  I need to create a collection, publish it, and have anonymous visitors find it

  # ONE scenario, in the style of oerexchange_admin_settings.feature: a real
  # create-publish-view round through the pages, with no JavaScript.
  #
  # The "add a resource to it" half of the round is deliberately absent, and
  # not because it does not matter: there is no way to seed a published
  # catalogue resource from Behat here. A declarative 'the following
  # "local_oerexchange > ..." exist:' step needs
  # tests/generator/behat_local_oerexchange_generator.php (core resolves that
  # exact class name in lib/tests/behat/behat_data_generators.php:197), which
  # this plugin does not have, and the only route to a published resource
  # through the UI is an .mbz upload followed by moderation. Adding and
  # removing items is covered end to end in PHPUnit instead, through the real
  # controller, in tests/collection_controller_test.php — an owner, a named
  # curator, an open contributor and a stranger each attempting it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |

  Scenario: A teacher creates a collection, publishes it, and an anonymous visitor finds it
    Given I log in as "teacher1"
    When I visit "/local/oerexchange/collection_edit.php"
    And I set the field "Title" to "Week one reading"
    # A new collection's button says "Create collection"; "Save collection"
    # appears once it exists (edit_collection_form::definition()).
    And I press "Create collection"
    # A new collection is always a draft: publishing is a separate, deliberate
    # act, so the owner is told so rather than left to guess.
    Then I should see "Week one reading"
    And I should see "This collection is a draft. Only you can see it. Publish it when you are ready."
    And I should see "Draft — only me"
    # Publishing is one change on the collection's own edit form, reached from
    # the page itself (the slug is minted from the title, so the owner never
    # has to know it).
    When I follow "Edit collection"
    And I set the field "Who can see this collection" to "Public — listed on this site"
    And I press "Save collection"
    Then I should see "Collection saved."
    And I should see "Week one reading"
    And I should see "Public — listed on this site"
    # And now a visitor with no account finds it in the blended catalogue,
    # labelled as a collection rather than as a single resource.
    When I log out
    And I visit "/local/oerexchange/index.php"
    Then I should see "Week one reading"
    And I should see "Collection"
