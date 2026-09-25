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
 * Tests for the assistant scope resolver.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for {@see scope}.
 *
 * @covers \block_openaiagent\local\scope
 */
final class scope_test extends \advanced_testcase {
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
     * A block in a course, or in one of its activities, is a course assistant.
     */
    public function test_course_and_module_blocks_are_course_scope(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        foreach ([\context_course::instance($course->id), \context_module::instance($page->cmid)] as $parent) {
            $scope = scope::for_block($this->block_in($parent), (int)$user->id);
            $this->assertTrue($scope->is_course());
            $this->assertSame((int)$course->id, $scope->courseid);
            $this->assertSame(CONTEXT_COURSE, (int)$scope->context->contextlevel);
            $this->assertSame(scope::AUTHENTICATED, $scope->audience);
        }
    }

    /**
     * Category, site home and Dashboard blocks each get their own scope.
     */
    public function test_platform_and_dashboard_scopes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();

        $scope = scope::for_block($this->block_in(\context_coursecat::instance($category->id)), (int)$user->id);
        $this->assertSame(scope::CATEGORY, $scope->type);
        $this->assertTrue($scope->is_platform());
        $this->assertSame((int)$category->id, $scope->categoryid);
        $this->assertSame((int)SITEID, $scope->courseid);
        $this->assertSame(CONTEXT_BLOCK, (int)$scope->context->contextlevel);

        $scope = scope::for_block($this->block_in(\context_course::instance(SITEID)), (int)$user->id);
        $this->assertSame(scope::SITE, $scope->type);

        $scope = scope::for_block($this->block_in(\context_system::instance()), (int)$user->id);
        $this->assertSame(scope::SITE, $scope->type);

        $scope = scope::for_block($this->block_in(\context_user::instance($user->id)), (int)$user->id);
        $this->assertSame(scope::DASHBOARD, $scope->type);
        $this->assertFalse($scope->is_platform());
    }

    /**
     * Guests and users who are not logged in are visitors.
     */
    public function test_audience(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(scope::AUTHENTICATED, scope::audience_of((int)$user->id));
        $this->assertSame(scope::VISITOR, scope::audience_of(0));
        $this->assertSame(scope::VISITOR, scope::audience_of((int)guest_user()->id));
    }

    /**
     * A request whose course and block do not belong together is refused.
     */
    public function test_request_must_pair_course_and_block(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $blockid = $this->block_in(\context_course::instance($course->id));

        $scope = scope::for_request((int)$course->id, $blockid, (int)$user->id);
        $this->assertSame((int)$course->id, $scope->courseid);

        // Legacy course-wide profile: the course id is the scope.
        $scope = scope::for_request((int)$other->id, 0, (int)$user->id);
        $this->assertTrue($scope->is_course());
        $this->assertSame((int)$other->id, $scope->courseid);

        $this->expectException(\invalid_parameter_exception::class);
        scope::for_request((int)$other->id, $blockid, (int)$user->id);
    }

    /**
     * An id that is not a block of this plugin is refused.
     */
    public function test_request_with_unknown_block(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(\invalid_parameter_exception::class);
        scope::for_request((int)SITEID, 999999, (int)$user->id);
    }

    /**
     * The page course is kept only inside the block's category tree and when visible.
     */
    public function test_page_course_validation(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $category = $generator->create_category();
        $subcategory = $generator->create_category(['parent' => $category->id]);
        $elsewhere = $generator->create_category();

        $inside = $generator->create_course(['category' => $subcategory->id]);
        $hidden = $generator->create_course(['category' => $subcategory->id, 'visible' => 0]);
        $outside = $generator->create_course(['category' => $elsewhere->id]);
        $generator->enrol_user($user->id, $inside->id, 'student');

        $blockid = $this->block_in(\context_coursecat::instance($category->id));

        $scope = scope::for_block($blockid, (int)$user->id, (int)$inside->id);
        $this->assertSame((int)$inside->id, $scope->pagecourseid);
        $this->assertTrue($scope->pagecourseenrolled);

        $this->assertSame(0, scope::for_block($blockid, (int)$user->id, (int)$outside->id)->pagecourseid);
        $this->assertSame(0, scope::for_block($blockid, (int)$user->id, (int)$hidden->id)->pagecourseid);
        $this->assertSame(0, scope::for_block($blockid, (int)$user->id, 999999)->pagecourseid);
        $this->assertSame(0, scope::for_block($blockid, (int)$user->id, (int)SITEID)->pagecourseid);

        // Visible and inside, but not enrolled: kept, so the enrolment options can use it.
        $stranger = $generator->create_user();
        $scope = scope::for_block($blockid, (int)$stranger->id, (int)$inside->id);
        $this->assertSame((int)$inside->id, $scope->pagecourseid);
        $this->assertFalse($scope->pagecourseenrolled);

        // A course block never carries a page course.
        $courseblock = $this->block_in(\context_course::instance($inside->id));
        $this->assertSame(0, scope::for_block($courseblock, (int)$user->id, (int)$inside->id)->pagecourseid);
    }
}
