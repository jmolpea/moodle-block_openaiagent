<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the catalogue, enrolment and site access tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;

/**
 * Unit tests for the tools a visitor may also use.
 *
 * @covers \block_openaiagent\mcp\platform\catalog
 * @covers \block_openaiagent\mcp\platform\tools\search_catalog
 * @covers \block_openaiagent\mcp\platform\tools\get_enrolment_options
 * @covers \block_openaiagent\mcp\platform\tools\get_site_access_info
 */
final class catalog_tools_test extends \advanced_testcase {
    /** @var \core_course_category Category holding the block. */
    private \core_course_category $category;

    /** @var int Category block id. */
    private int $blockid;

    /**
     * Create a category with an assistant block.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->category = $this->getDataGenerator()->create_category(['name' => 'Posgrado']);
        $block = $this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($this->category->id)->id,
            'pagetypepattern' => '*',
        ]);
        $this->blockid = (int)$block->id;
    }

    /**
     * Scope of the category block, logged in as a user (or not logged in for 0).
     *
     * @param int $userid User id.
     * @param int $pagecourseid Page course.
     * @return scope
     */
    private function scope_for(int $userid, int $pagecourseid = 0): scope {
        $this->setUser($userid);
        return scope::for_block($this->blockid, $userid, $pagecourseid);
    }

    /**
     * Names of the courses a search returns.
     *
     * @param array $result Tool output.
     * @return string[]
     */
    private static function names(array $result): array {
        return array_column($result['courses'], 'name');
    }

    /**
     * Only what Moodle would list to this user, inside the block's category, not ended unless theirs.
     */
    public function test_search_catalog_respects_visibility_and_scope(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $sub = $generator->create_category(['parent' => $this->category->id]);
        $restricted = $generator->create_category(['parent' => $this->category->id]);
        $elsewhere = $generator->create_category();
        $now = time();

        $generator->create_course(['fullname' => 'Master Data', 'category' => $sub->id,
            'summary' => '<p>Learn <b>data</b> analysis</p>']);
        $generator->create_course(['fullname' => 'Hidden Data', 'category' => $sub->id, 'visible' => 0]);
        $generator->create_course(['fullname' => 'Outside Data', 'category' => $elsewhere->id]);
        $generator->create_course(['fullname' => 'Restricted Data', 'category' => $restricted->id]);
        $generator->create_course(['fullname' => 'Ended Data', 'category' => $sub->id,
            'startdate' => $now - 60 * DAYSECS, 'enddate' => $now - DAYSECS]);
        $mine = $generator->create_course(['fullname' => 'Ended Mine Data', 'category' => $sub->id,
            'startdate' => $now - 60 * DAYSECS, 'enddate' => $now - DAYSECS]);
        $generator->enrol_user($user->id, $mine->id, 'student');

        // The site restricts one subcategory's course list for authenticated users.
        $userrole = $this->get_authenticated_role();
        assign_capability(
            'moodle/category:viewcourselist',
            CAP_PROHIBIT,
            $userrole,
            \context_coursecat::instance($restricted->id)->id,
            true
        );

        foreach (['', 'Data'] as $query) {
            $result = registry::call('moodle.search_catalog', ['query' => $query], $this->scope_for((int)$user->id));
            $names = self::names($result);
            $this->assertContains('Master Data', $names, "query '$query'");
            $this->assertContains('Ended Mine Data', $names, "query '$query'");
            $this->assertNotContains('Hidden Data', $names, "query '$query'");
            $this->assertNotContains('Outside Data', $names, "query '$query'");
            $this->assertNotContains('Restricted Data', $names, "query '$query'");
            $this->assertNotContains('Ended Data', $names, "query '$query'");
        }

        $bynames = array_column($result['courses'], null, 'name');
        $this->assertSame('Learn data analysis', $bynames['Master Data']['summary']);
        $this->assertTrue($bynames['Ended Mine Data']['is_mine']);
        $this->assertSame('Posgrado', $result['limited_to_category']);
    }

    /**
     * A custom field hidden from participants is not sent.
     */
    public function test_search_catalog_custom_field_visibility(): void {
        $generator = $this->getDataGenerator();
        $customfields = $generator->get_plugin_generator('core_customfield');
        $fieldcategory = $customfields->create_category();
        $customfields->create_field(['categoryid' => $fieldcategory->get('id'), 'shortname' => 'objectives',
            'name' => 'Objectives', 'type' => 'text', 'configdata' => ['visibility' => 2]]);
        $customfields->create_field(['categoryid' => $fieldcategory->get('id'), 'shortname' => 'internalcode',
            'name' => 'Internal code', 'type' => 'text', 'configdata' => ['visibility' => 0]]);
        $generator->create_course(['fullname' => 'Leadership', 'category' => $this->category->id,
            'customfield_objectives' => 'Lead teams', 'customfield_internalcode' => 'SECRET-42']);

        $result = registry::call(
            'moodle.search_catalog',
            ['query' => 'Leadership'],
            $this->scope_for((int)$generator->create_user()->id)
        );

        $this->assertSame(['Objectives' => 'Lead teams'], $result['courses'][0]['fields']);
        $this->assertStringNotContainsString('SECRET-42', json_encode($result));
    }

    /**
     * A visitor may browse the catalogue.
     */
    public function test_search_catalog_for_a_visitor(): void {
        $this->getDataGenerator()->create_course(['fullname' => 'Open Course', 'category' => $this->category->id]);

        set_config('forcelogin', 0);
        $result = registry::call('moodle.search_catalog', [], $this->scope_for(0));
        $this->assertContains('Open Course', self::names($result));
        $this->assertFalse($result['courses'][0]['is_mine']);

        // A site that forces login shows visitors nothing, as Moodle itself does.
        set_config('forcelogin', 1);
        $result = registry::call('moodle.search_catalog', [], $this->scope_for(0));
        $this->assertSame([], $result['courses']);
    }

    /**
     * Keys are never returned; costs, cohorts, payments and visitors are described.
     */
    public function test_get_enrolment_options(): void {
        global $DB;
        set_config('enrol_plugins_enabled', 'manual,guest,self,cohort,fee');
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course(['fullname' => 'Paid', 'category' => $this->category->id]);

        $self = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self'], '*', MUST_EXIST);
        $DB->update_record('enrol', (object) ['id' => $self->id, 'status' => ENROL_INSTANCE_ENABLED,
            'password' => 'TopSecretKey', 'customint6' => 1]);
        $cohort = $generator->create_cohort();
        enrol_get_plugin('self')->add_instance($course, ['customint5' => $cohort->id, 'customint6' => 1,
            'status' => ENROL_INSTANCE_ENABLED]);
        enrol_get_plugin('fee')->add_instance($course, ['cost' => 120, 'currency' => 'EUR', 'customint1' => 1]);

        $result = registry::call(
            'moodle.get_enrolment_options',
            ['target_course_id' => $course->id],
            $this->scope_for((int)$user->id)
        );
        $this->assertStringNotContainsString('TopSecretKey', json_encode($result));
        $byrestriction = array_column($result['methods'], null, 'restriction');

        $this->assertTrue($byrestriction[catalog::RESTRICTION_KEY]['requires_key']);
        $this->assertTrue($byrestriction[catalog::RESTRICTION_KEY]['available_to_this_user']);
        $this->assertSame('needs_key', $byrestriction[catalog::RESTRICTION_KEY]['not_available_reason']);
        $this->assertSame('cohort_only', $byrestriction[catalog::RESTRICTION_COHORT]['not_available_reason']);
        $this->assertTrue($byrestriction[catalog::RESTRICTION_PAYMENT]['requires_payment']);
        $this->assertStringContainsString('120', $byrestriction[catalog::RESTRICTION_PAYMENT]['cost']);
        $this->assertSame(catalog::RESTRICTION_MANAGED, $byrestriction[catalog::RESTRICTION_MANAGED]['restriction']);
        $this->assertNull($byrestriction[catalog::RESTRICTION_MANAGED]['available_to_this_user']);
        $this->assertFalse($result['already_enrolled']);

        // Visitors learn the same methods but must log in first.
        set_config('forcelogin', 0);
        $result = registry::call('moodle.get_enrolment_options', ['target_course_id' => $course->id], $this->scope_for(0));
        $this->assertFalse($result['logged_in']);
        $payment = array_column($result['methods'], null, 'restriction')[catalog::RESTRICTION_PAYMENT];
        $this->assertSame('requires_login', $payment['not_available_reason']);
        $this->assertStringNotContainsString('TopSecretKey', json_encode($result));
    }

    /**
     * The course being viewed is the default; courses the user cannot see are not described.
     */
    public function test_get_enrolment_options_course_resolution(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $visible = $generator->create_course(['fullname' => 'Visible', 'category' => $this->category->id]);
        $hidden = $generator->create_course(['fullname' => 'Hidden', 'category' => $this->category->id, 'visible' => 0]);

        $result = registry::call('moodle.get_enrolment_options', [], $this->scope_for((int)$user->id, (int)$visible->id));
        $this->assertSame((int)$visible->id, $result['course_id']);

        $result = registry::call(
            'moodle.get_enrolment_options',
            ['target_course_id' => $hidden->id],
            $this->scope_for((int)$user->id)
        );
        $this->assertSame('course_not_found', $result['error']);
        $this->assertStringNotContainsString('Hidden', json_encode($result));

        $result = registry::call('moodle.get_enrolment_options', [], $this->scope_for((int)$user->id));
        $this->assertSame('no_course', $result['error']);
    }

    /**
     * Access facts follow the site settings, and support follows its availability rule.
     */
    public function test_get_site_access_info(): void {
        global $CFG;
        $CFG->registerauth = '';
        $CFG->supportavailability = CONTACT_SUPPORT_AUTHENTICATED;

        $result = registry::call('moodle.get_site_access_info', [], $this->scope_for(0));
        $this->assertFalse($result['self_registration']);
        $this->assertNull($result['signup_url']);
        $this->assertNull($result['support_url']);
        $this->assertFalse($result['logged_in']);

        $CFG->supportavailability = CONTACT_SUPPORT_ANYONE;
        $result = registry::call('moodle.get_site_access_info', [], $this->scope_for(0));
        $this->assertStringContainsString('/user/contactsitesupport.php', $result['support_url']);
    }

    /**
     * The authenticated user role id.
     *
     * @return int
     */
    private function get_authenticated_role(): int {
        global $CFG;
        return (int)$CFG->defaultuserroleid;
    }
}
