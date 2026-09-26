@block @block_openaiagent
Feature: Use the assistant outside a course
  In order to help participants with their courses, the catalogue and enrolment
  As an administrator
  I need the block to work on a course category and to say clearly where it cannot

  Background:
    Given the following "categories" exist:
      | name     | category | idnumber |
      | Posgrado | 0        | POS      |
    And the following "courses" exist:
      | fullname      | shortname | category |
      | Master Data   | MD01      | POS      |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Marta     | Ruiz     |
      | visitor1 | Pablo     | Gil      |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | MD01   | student |
    And the following config values are set as admin:
      | enabled | 1        | block_openaiagent |
      | apikey  | test-key | block_openaiagent |

  Scenario: A logged-in user with no role in any course gets the chat on the category page
    Given the following "blocks" exist:
      | blockname   | contextlevel | reference | pagetypepattern |
      | openaiagent | Category     | POS       | *               |
    And I log in as "visitor1"
    When I am on course index
    And I follow "Posgrado"
    Then I should see "How can I help you today?" in the "Smart Tutor & Support AI" "block"

  Scenario: A category assistant cannot be reached by someone who is not logged in
    Given the following "blocks" exist:
      | blockname   | contextlevel | reference | pagetypepattern |
      | openaiagent | Category     | POS       | *               |
    # The category page itself must be open to visitors, so that only the block is being tested.
    And the following config values are set as admin:
      | forcelogin | 0 |
    When I am on course index
    And I follow "Posgrado"
    Then I should not see "How can I help you today?"

  Scenario: A category assistant opened to visitors greets someone who is not logged in
    Given the following "blocks" exist:
      | blockname   | contextlevel | reference | pagetypepattern |
      | openaiagent | Category     | POS       | *               |
    And the following config values are set as admin:
      | forcelogin | 0 |
    And the assistant of the "POS" category is open to visitors
    When I am on course index
    And I follow "Posgrado"
    Then I should see "Hi!" in the "Smart Tutor & Support AI" "block"
    And I should see "How can I help you today?" in the "Smart Tutor & Support AI" "block"

  Scenario: Opening an assistant to visitors changes nothing for a logged-in user
    Given the following "blocks" exist:
      | blockname   | contextlevel | reference | pagetypepattern |
      | openaiagent | Category     | POS       | *               |
    And the assistant of the "POS" category is open to visitors
    And I log in as "visitor1"
    When I am on course index
    And I follow "Posgrado"
    Then I should see "Hi, Pablo!" in the "Smart Tutor & Support AI" "block"

  Scenario: On the Dashboard the block tells its owner to move it instead of opening a chat
    Given the following "blocks" exist:
      | blockname   | contextlevel | reference | pagetypepattern | defaultregion |
      | openaiagent | User         | student1  | my-index        | side-pre      |
    And I log in as "student1"
    When I am on homepage
    And I follow "Dashboard"
    Then I should see "The assistant does not work on the Dashboard"
    And I should not see "How can I help you today?"
