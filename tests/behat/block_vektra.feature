@block @block_vektra
Feature: Vektra block on a course page
  In order to set up the AI assistant on a course
  As an admin
  I need the block to render on the course page

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | vektra    | Course       | C1        | course-view-*   | side-pre      |

  Scenario: Admin sees the setup notice when the connection is not configured
    Given I log in as "admin"
    When I am on "Course 1" course homepage
    Then I should see "Vektra is not configured."
