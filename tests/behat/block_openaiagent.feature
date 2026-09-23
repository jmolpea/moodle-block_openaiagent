@block @block_openaiagent
Feature: Place the Smart Tutor & Support AI block in a course
  In order to give participants a course assistant
  As a teacher or an administrator
  I need the block to appear in the course and to say clearly when it cannot run

  Background:
    Given the following "courses" exist:
      | fullname         | shortname | category |
      | Project Managing | PM101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Alan      | Pierce   |
      | student1 | Marta     | Ruiz     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | PM101  | editingteacher |
      | student1 | PM101  | student        |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference |
      | openaiagent | Course      | PM101     |

  Scenario: The block carries the product name for a teacher
    Given I log in as "teacher1"
    When I am on "Project Managing" course homepage
    Then I should see "Smart Tutor & Support AI"

  Scenario: The block carries the product name for a student
    Given I log in as "student1"
    When I am on "Project Managing" course homepage
    Then I should see "Smart Tutor & Support AI"

  Scenario: With no AI provider key the block says so instead of offering a chat
    Given the following config values are set as admin:
      | enabled | 1 | block_openaiagent |
      | apikey  |   | block_openaiagent |
    And I log in as "student1"
    When I am on "Project Managing" course homepage
    Then I should see "The assistant is not configured" in the "Smart Tutor & Support AI" "block"

  Scenario: With Moodle's AI tools allowed for the course, the chat is offered
    Given the following config values are set as admin:
      | enabled | 1        | block_openaiagent |
      | apikey  | test-key | block_openaiagent |
    And Moodle AI tools are enabled in the "PM101" course
    And I log in as "student1"
    When I am on "Project Managing" course homepage
    Then I should see "How can I help you today?" in the "Smart Tutor & Support AI" "block"
    And I should not see "The assistant is not available in this course." in the "Smart Tutor & Support AI" "block"

  Scenario: With Moodle's AI tools turned off for the course, a student gets a notice instead of the chat
    Given the following config values are set as admin:
      | enabled | 1        | block_openaiagent |
      | apikey  | test-key | block_openaiagent |
    And Moodle AI tools are disabled in the "PM101" course
    And I log in as "student1"
    When I am on "Project Managing" course homepage
    Then I should see "The assistant is not available in this course." in the "Smart Tutor & Support AI" "block"
    And I should not see "How can I help you today?" in the "Smart Tutor & Support AI" "block"

  Scenario: With Moodle's AI tools turned off for the course, a teacher is told where to turn them back on
    Given the following config values are set as admin:
      | enabled | 1        | block_openaiagent |
      | apikey  | test-key | block_openaiagent |
    And Moodle AI tools are disabled in the "PM101" course
    And I log in as "teacher1"
    When I am on "Project Managing" course homepage
    Then I should see "Allow AI tools for this course" in the "Smart Tutor & Support AI" "block"

  Scenario: An administrator reaches the plugin settings and finds the licence field
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=blocksettingopenaiagent"
    Then I should see "Smart Tutor & Support AI"
    And I should see "License key"
