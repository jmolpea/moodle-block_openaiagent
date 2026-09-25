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
 * Tests for the platform assistant's user tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\tools\get_my_courses;

/**
 * Unit tests for the platform registry and the per-user tools.
 *
 * @covers \block_openaiagent\mcp\platform\registry
 * @covers \block_openaiagent\mcp\platform\tools\get_my_profile
 * @covers \block_openaiagent\mcp\platform\tools\get_my_courses
 * @covers \block_openaiagent\mcp\platform\tools\get_my_course_progress
 * @covers \block_openaiagent\mcp\platform\tools\get_my_deadlines
 * @covers \block_openaiagent\mcp\platform\tools\get_my_grades
 * @covers \block_openaiagent\mcp\platform\tools\get_my_notifications
 */
final class platform_tools_test extends \advanced_testcase {
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
        $this->category = $this->getDataGenerator()->create_category();
        $block = $this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($this->category->id)->id,
            'pagetypepattern' => '*',
        ]);
        $this->blockid = (int)$block->id;
    }

    /**
     * Scope of the category block for a user, logged in as that user.
     *
     * @param \stdClass $user User.
     * @return scope
     */
    private function scope_for(\stdClass $user): scope {
        $this->setUser($user);
        return scope::for_block($this->blockid, (int)$user->id);
    }

    /**
     * Visitors reach only the public tools, and cannot call a personal one.
     */
    public function test_registry_filters(): void {
        $user = $this->getDataGenerator()->create_user();
        $scope = $this->scope_for($user);

        $this->assertEqualsCanonicalizing(registry::default_names(), array_keys(registry::permitted($scope)));

        // Not logged in, or guest: only the three tools about the site and its
        // catalogue, never one about a person, whatever is configured.
        $public = ['moodle.search_catalog', 'moodle.get_enrolment_options', 'moodle.get_site_access_info'];
        $this->setUser(0);
        $this->assertEqualsCanonicalizing($public, array_keys(registry::permitted(scope::for_block($this->blockid, 0))));
        $this->setGuestUser();
        $guest = scope::for_block($this->blockid, (int)guest_user()->id);
        $this->assertEqualsCanonicalizing($public, array_keys(registry::permitted($guest)));
        $this->expectExceptionObject(new \moodle_exception('mcp_unknown_tool', 'block_openaiagent', '', 'moodle.get_my_courses'));
        registry::call('moodle.get_my_courses', [], $guest);
    }

    /**
     * Stored choices and course blocks.
     */
    public function test_registry_configuration(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $scope = $this->scope_for($user);

        // A course block has no platform tools.
        $course = $this->getDataGenerator()->create_course();
        $courseblock = $this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $this->assertSame([], registry::permitted(scope::for_block((int)$courseblock->id, (int)$user->id)));

        // An explicit "off" is honoured; a stored course-tool choice is irrelevant.
        foreach (['moodle.get_my_grades' => 0, 'moodle.get_course_outline' => 1] as $name => $enabled) {
            $DB->insert_record('block_openaiagent_coursetools', (object) [
                'courseid' => SITEID, 'blockinstanceid' => $this->blockid, 'toolname' => $name,
                'enabled' => $enabled, 'requirescapability' => '', 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
        $names = registry::enabled_names($scope);
        $this->assertNotContains('moodle.get_my_grades', $names);
        $this->assertNotContains('moodle.get_course_outline', $names);
        $this->assertContains('moodle.get_my_courses', $names);

        // Function names use the provider-safe encoding.
        foreach (registry::function_tools($scope, $names) as $definition) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $definition['name']);
            $this->assertArrayNotHasKey('user_id', (array)$definition['parameters']['properties']);
        }
    }

    /**
     * The user always comes from the scope, never from the arguments; unknown tools fail.
     */
    public function test_call_ignores_identity_arguments(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana']);
        $victim = $this->getDataGenerator()->create_user(['firstname' => 'Victima']);
        $scope = $this->scope_for($user);

        $result = registry::call('moodle.get_my_profile', ['user_id' => $victim->id], $scope);
        $this->assertSame('Ana', $result['firstname']);

        $this->expectException(\moodle_exception::class);
        registry::call('moodle.get_course_outline', [], $scope);
    }

    /**
     * The profile carries sign-in facts and no contact details.
     */
    public function test_get_my_profile(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'email' => 'ana@example.org']);
        $result = registry::call('moodle.get_my_profile', [], $this->scope_for($user));

        $this->assertSame('Ana', $result['firstname']);
        $this->assertTrue($result['password_managed_by_moodle']);
        $this->assertStringContainsString('/login/change_password.php', $result['password_help_url']);
        $this->assertStringNotContainsString('ana@example.org', json_encode($result));
        $this->assertStringNotContainsString($user->username, json_encode($result));
    }

    /**
     * Each enrolment state is reported as such, with the date that explains it.
     */
    public function test_get_my_courses_access_status(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $now = time();

        $make = function (array $course, array $enrol = []) use ($generator, $user) {
            $course = $generator->create_course($course + ['category' => $this->category->id]);
            $generator->enrol_user(
                $user->id,
                $course->id,
                'student',
                'manual',
                $enrol['timestart'] ?? 0,
                $enrol['timeend'] ?? 0,
                $enrol['status'] ?? ENROL_USER_ACTIVE
            );
            return $course;
        };
        $active = $make(['fullname' => 'Active']);
        $make(['fullname' => 'Suspended'], ['status' => ENROL_USER_SUSPENDED]);
        $expired = $make(['fullname' => 'Expired'], ['timeend' => $now - DAYSECS]);
        $future = $make(['fullname' => 'Future'], ['timestart' => $now + DAYSECS]);
        $hidden = $make(['fullname' => 'Hidden', 'visible' => 0]);
        $make(['fullname' => 'Ended', 'startdate' => $now - 30 * DAYSECS, 'enddate' => $now - DAYSECS]);

        $result = registry::call('moodle.get_my_courses', [], $this->scope_for($user));
        $this->assertSame(6, $result['total']);
        $bystatus = array_column($result['courses'], 'access_status', 'name');

        $this->assertSame(get_my_courses::ACTIVE, $bystatus['Active']);
        $this->assertSame(get_my_courses::SUSPENDED, $bystatus['Suspended']);
        $this->assertSame(get_my_courses::EXPIRED, $bystatus['Expired']);
        $this->assertSame(get_my_courses::NOT_STARTED, $bystatus['Future']);
        $this->assertSame(get_my_courses::COURSE_HIDDEN, $bystatus['Hidden']);
        $this->assertSame(get_my_courses::COURSE_ENDED, $bystatus['Ended']);

        $bycourse = array_column($result['courses'], null, 'id');
        $this->assertNotNull($bycourse[$expired->id]['access_status_date']);
        $this->assertNotNull($bycourse[$future->id]['access_status_date']);
        $this->assertNull($bycourse[$hidden->id]['url']);
        $this->assertTrue($bycourse[$active->id]['in_this_category']);
        $this->assertNull($bycourse[$active->id]['progress_percent']);

        // Paging.
        $page = registry::call('moodle.get_my_courses', ['limit' => 4], $this->scope_for($user));
        $this->assertCount(4, $page['courses']);
        $this->assertTrue($page['has_more']);
    }

    /**
     * Progress is only answered for the participant's own, reachable courses.
     */
    public function test_get_my_course_progress(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $mine = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $mine->id], ['completion' => COMPLETION_TRACKING_MANUAL]);
        $notmine = $generator->create_course(['enablecompletion' => 1]);
        $untracked = $generator->create_course(['enablecompletion' => 0]);
        $generator->enrol_user($user->id, $mine->id, 'student');
        $generator->enrol_user($user->id, $untracked->id, 'student');
        $scope = $this->scope_for($user);

        $result = registry::call('moodle.get_my_course_progress', ['target_course_id' => $mine->id], $scope);
        $this->assertTrue($result['tracks_completion']);
        $this->assertSame(1, $result['activities_pending_count']);
        $this->assertSame(0, $result['activities_done_count']);

        $result = registry::call('moodle.get_my_course_progress', ['target_course_id' => $notmine->id], $scope);
        $this->assertSame('not_your_course', $result['error']);
        $this->assertArrayNotHasKey('activities_pending', $result);

        $result = registry::call('moodle.get_my_course_progress', ['target_course_id' => $untracked->id], $scope);
        $this->assertFalse($result['tracks_completion']);
    }

    /**
     * Due work is listed; work in a course the user is not in, or hidden, is not.
     */
    public function test_get_my_deadlines(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $generator->enrol_user($user->id, $course->id, 'student');
        $due = time() + 3 * DAYSECS;
        $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay', 'duedate' => $due]);
        $generator->create_module('assign', ['course' => $course->id, 'name' => 'Secret', 'duedate' => $due,
            'visible' => 0]);
        $generator->create_module('assign', ['course' => $other->id, 'name' => 'Elsewhere', 'duedate' => $due]);

        $result = registry::call('moodle.get_my_deadlines', [], $this->scope_for($user));
        $names = array_merge(array_column($result['deadlines'], 'name'), array_column($result['events'], 'name'));

        $this->assertNotEmpty(array_filter($names, fn($n) => strpos($n, 'Essay') !== false));
        $this->assertEmpty(array_filter($names, fn($n) => strpos($n, 'Secret') !== false));
        $this->assertEmpty(array_filter($names, fn($n) => strpos($n, 'Elsewhere') !== false));
    }

    /**
     * Course totals appear as on the overview report; a hidden total does not.
     */
    public function test_get_my_grades(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $visible = $generator->create_course(['fullname' => 'Graded']);
        $hidden = $generator->create_course(['fullname' => 'Hidden total']);
        foreach ([$visible, $hidden] as $course) {
            $generator->enrol_user($user->id, $course->id, 'student');
            $item = $generator->create_grade_item(['courseid' => $course->id, 'grademax' => 100]);
            $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $user->id], false);
            $grade->finalgrade = 80;
            $grade->rawgrade = 80;
            $grade->insert();
        }
        \grade_item::fetch_course_item($hidden->id)->set_hidden(1);
        grade_regrade_final_grades($visible->id);
        grade_regrade_final_grades($hidden->id);

        $result = registry::call('moodle.get_my_grades', [], $this->scope_for($user));
        $bycourse = array_column($result['courses'], 'grade', 'course');

        $this->assertArrayHasKey('Graded', $bycourse);
        $this->assertStringContainsString('80', (string)$bycourse['Graded']);
        $this->assertStringNotContainsString('80', (string)($bycourse['Hidden total'] ?? ''));
    }

    /**
     * Counts and subjects, never message bodies or senders.
     */
    public function test_get_my_notifications(): void {
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        $user = $this->getDataGenerator()->create_user();

        $message = new \core\message\message();
        $message->component = 'moodle';
        $message->name = 'instantmessage';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = 'Your certificate is ready';
        $message->fullmessage = 'SECRET BODY';
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = 'SECRET SMALL';
        $message->notification = 1;
        $sink->close();
        message_send($message);

        $result = registry::call('moodle.get_my_notifications', [], $this->scope_for($user));
        $this->assertSame(1, $result['unread_notifications']);
        $this->assertSame('Your certificate is ready', $result['latest_unread_notifications'][0]['subject']);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
    }
}
