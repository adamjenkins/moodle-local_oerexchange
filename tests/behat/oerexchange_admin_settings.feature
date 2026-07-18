@local @local_oerexchange
Feature: OER Exchange admin settings and anonymous browsing
  In order to configure and use the OER Exchange
  As an admin or an anonymous visitor
  I need to reach its settings page, and browse the public catalogue without logging in

  Scenario: The OER Exchange settings page is reachable from Site administration
    Given I log in as "admin"
    And I navigate to "Plugins > OER Exchange > General settings" in site administration
    Then I should see "Sandbox"
    And I should see "Anonymous access"

  Scenario: An anonymous visitor can browse the catalogue page without logging in
    When I visit "/local/oerexchange/index.php"
    Then I should see "No resources have been shared to this Exchange yet."
