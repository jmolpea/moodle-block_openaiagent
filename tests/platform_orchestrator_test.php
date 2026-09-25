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
 * Tests for a category or site assistant turn, end to end.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\course_config;
use block_openaiagent\local\defaults;
use block_openaiagent\local\scope;

/**
 * Platform assistant turns through the orchestrator.
 *
 * @covers \block_openaiagent\orchestrator
 * @covers \block_openaiagent\local\platform_profile
 */
final class platform_orchestrator_test extends \advanced_testcase {
    /** @var \core_course_category Category holding the block. */
    private \core_course_category $category;

    /** @var int Category block id. */
    private int $blockid;

    /** @var \stdClass Participant. */
    private \stdClass $user;

    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * A working plugin with a category assistant and a participant.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        defaults::install();
        set_config('enabled', 1, 'block_openaiagent');
        set_config('rate_limit_per_user_minute', 0, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');
        set_config('embeddings_provider', 'none', 'block_openaiagent');
        set_config('enable_query_rewrite', 0, 'block_openaiagent');
        set_config('enable_file_search', 1, 'block_openaiagent');

        $generator = $this->getDataGenerator();
        $this->category = $generator->create_category(['name' => 'Posgrado']);
        $this->blockid = (int)$generator->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($this->category->id)->id,
            'pagetypepattern' => '*',
        ])->id;
        $this->user = $generator->create_user(['firstname' => 'Ana']);
        $this->setUser($this->user);
    }

    /**
     * Run one turn in the category assistant.
     *
     * @param fake_client $fake Fake provider.
     * @param string $message Message.
     * @param int $pagecourseid Course of the page (0 = none).
     * @return array
     */
    private function turn(fake_client $fake, string $message, int $pagecourseid = 0): array {
        $scope = scope::for_block($this->blockid, (int)$this->user->id, $pagecourseid);
        return (new orchestrator($fake))->handle_message(
            (int)SITEID,
            (int)$this->user->id,
            $message,
            null,
            $this->blockid,
            $scope
        );
    }

    /**
     * The assistant gets the platform tools and prompt, and none of the course tools.
     */
    public function test_assistant_gets_platform_tools_and_prompt(): void {
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $this->turn($fake, 'Which courses am I enrolled in?');

        $request = $fake->last_agent_request();
        $names = array_column($request->tools, 'name');
        $this->assertContains('moodle__get_my_courses', $names);
        $this->assertContains('moodle__search_catalog', $names);
        $this->assertNotContains('moodle__get_course_outline', $names);
        $this->assertStringStartsWith('You are the platform assistant of this Moodle site.', $request->instructions);
        $this->assertStringContainsString('course category "Posgrado"', $request->instructions);
        $this->assertStringContainsString('"Ana"', $request->instructions);
        $this->assertStringNotContainsString('the current course is', $request->instructions);
    }

    /**
     * The router and the institutional agent use the platform prompts.
     */
    public function test_router_and_tutor_use_platform_prompts(): void {
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';
        $this->turn($fake, 'How do I request my degree certificate?');

        $this->assertStringStartsWith(
            'You are an intent classifier for the platform assistant',
            $fake->last_router_request()->instructions
        );
        $this->assertStringStartsWith(
            'You are the institutional information assistant',
            $fake->last_agent_request()->instructions
        );
    }

    /**
     * A platform tool the model calls runs as the session user.
     */
    public function test_platform_tool_runs_in_the_loop(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Excel Avanzado']);
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id, 'student');

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttoolcalls = [['id' => 'call_1', 'name' => 'moodle__get_my_courses', 'arguments' => []]];
        $fake->agenttext = 'You are enrolled in Excel Avanzado.';
        $result = $this->turn($fake, 'Which courses am I enrolled in?');

        $this->assertTrue($result['success']);
        $toolresult = '';
        foreach ($fake->last_agent_request()->messages as $message) {
            if ($message['role'] === 'tool') {
                $toolresult = $message['content'];
            }
        }
        $this->assertStringContainsString('Excel Avanzado', $toolresult);
    }

    /**
     * Inside a course they are enrolled in, the course tools join, bound to that course.
     */
    public function test_course_tools_inside_an_enrolled_course(): void {
        $generator = $this->getDataGenerator();
        $mine = $generator->create_course(['category' => $this->category->id, 'fullname' => 'Mine']);
        $notmine = $generator->create_course(['category' => $this->category->id, 'fullname' => 'Not mine']);
        $generator->enrol_user($this->user->id, $mine->id, 'student');

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttoolcalls = [['id' => 'call_1', 'name' => 'moodle__get_context', 'arguments' => [
            'course_id' => $notmine->id,
        ]]];
        $this->turn($fake, 'Where am I?', (int)$mine->id);

        $request = $fake->last_agent_request();
        $this->assertContains('moodle__get_course_outline', array_column($request->tools, 'name'));
        $this->assertStringContainsString('looking at the course "Mine" (they are enrolled in it', $request->instructions);
        $toolresult = '';
        foreach ($request->messages as $message) {
            if ($message['role'] === 'tool') {
                $toolresult = $message['content'];
            }
        }
        // Bound to the validated page course, whatever the model asked for.
        $this->assertStringContainsString('"course_id":' . $mine->id, $toolresult);

        // Not enrolled: no course tools, and a forced call is refused.
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttoolcalls = [['id' => 'call_1', 'name' => 'moodle__get_course_outline', 'arguments' => []]];
        $this->turn($fake, 'Where am I?', (int)$notmine->id);
        $request = $fake->last_agent_request();
        $this->assertNotContains('moodle__get_course_outline', array_column($request->tools, 'name'));
        $this->assertStringContainsString('tool_not_available', json_encode($request->messages));
    }

    /**
     * A block saved with the course defaults moves to the platform wording; a written prompt stays.
     */
    public function test_saved_course_defaults_become_platform_defaults(): void {
        course_config::save((int)SITEID, ['enabled' => 1, 'assistantprompt' => defaults::ASSISTANT_PROMPT], $this->blockid);
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $this->turn($fake, 'Which courses am I enrolled in?');
        $this->assertStringStartsWith('You are the platform assistant', $fake->last_agent_request()->instructions);

        course_config::save((int)SITEID, ['enabled' => 1, 'assistantprompt' => 'Custom institutional assistant.'], $this->blockid);
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $this->turn($fake, 'Which courses am I enrolled in?');
        $this->assertStringStartsWith('Custom institutional assistant.', $fake->last_agent_request()->instructions);
    }
}
