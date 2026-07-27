@local @local_oerexchange
Feature: OER Exchange admin settings and anonymous browsing
  In order to configure and use the OER Exchange
  As an admin or an anonymous visitor
  I need to reach its settings page, and browse the public catalogue without logging in

  Scenario: The OER Exchange settings page is reachable from Site administration
    Given I log in as "admin"
    # Two steps, not one path. settings.php nests this plugin's category under
    # 'localplugins', and local_oerclient — installed alongside it on any site
    # that runs both halves of the platform, including this one — publishes a
    # settings page called "General settings" under the same parent. A single
    # "... > General settings" path matched OER Client's page instead of this
    # one, so the scenario was asserting against the wrong plugin's settings.
    And I navigate to "Plugins > Local plugins > OER Exchange" in site administration
    And I follow "General settings"
    Then I should see "Sandbox"
    And I should see "Anonymous access"

  Scenario: An anonymous visitor can browse the catalogue page without logging in
    When I visit "/local/oerexchange/index.php"
    Then I should see "No resources have been shared to this Exchange yet."
