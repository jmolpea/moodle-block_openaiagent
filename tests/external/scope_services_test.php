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
 * Tests for how the chat web services resolve their scope.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use core_external\external_api;

/**
 * Scope checks on the chat web services.
 *
 * @covers \block_openaiagent\external\get_conversation
 * @covers \block_openaiagent\external\reset_conversation
 * @covers \block_openaiagent\local\scope
 */
final class scope_services_test extends \advanced_testcase {
    /**
     * Add an assistant block to a context.
     *
     * @param \context $context Parent context.
     * @return int Block instance id.
     */
    private function block_in(\context $context): int {
        $block = $this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => $context->id,
            'pagetypepattern' => '*',
        ]);
        return (int)$block->id;
    }

    /**
     * A user with no role anywhere can use a category assistant.
     *
     * Category assistants key their data under the site course but are checked
     * in their own block context, where the authenticated user role applies.
     */
    public function test_category_block_works_for_any_logged_in_user(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $blockid = $this->block_in(\context_coursecat::instance($category->id));
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            get_conversation::execute_returns(),
            get_conversation::execute((int)SITEID, 0, $blockid)
        );
        $this->assertSame(0, $result['conversationid']);

        $result = external_api::clean_returnvalue(
            reset_conversation::execute_returns(),
            reset_conversation::execute((int)SITEID, $blockid)
        );
        $this->assertNotEmpty($result);
    }

    /**
     * A category block cannot be reached through a course id.
     */
    public function test_category_block_with_a_course_id_is_refused(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $blockid = $this->block_in(\context_coursecat::instance($category->id));
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $this->expectException(\invalid_parameter_exception::class);
        get_conversation::execute((int)$course->id, 0, $blockid);
    }

    /**
     * A course block still requires access to its course.
     */
    public function test_course_block_still_requires_the_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $blockid = $this->block_in(\context_course::instance($course->id));
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        get_conversation::execute((int)$course->id, 0, $blockid);
    }
}
