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
 * Characterisation test: what a course assistant sends to the model.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\course_config;
use block_openaiagent\local\defaults;

/**
 * Pins the course assistant to its 4.17.1 behaviour.
 *
 * The platform assistant (category and site home) is being built alongside the
 * course assistant, which is in production and must not change. This test
 * records, for a block placed in a course, every request the model receives on
 * each route -- instructions, tool list, tool schemas, model and limits -- plus
 * the default tool list, and compares them with a stored baseline.
 *
 * A failure here means course behaviour changed. That is never a reason to
 * regenerate the baseline on its own: the change has to be intended and agreed
 * first. To regenerate, run this test once with the environment variable
 * BLOCK_OPENAIAGENT_WRITE_BASELINE=1.
 *
 * @covers \block_openaiagent\orchestrator
 * @covers \block_openaiagent\local\course_config
 */
final class course_scope_baseline_test extends \advanced_testcase {
    /** @var string Baseline file, relative to the plugin directory. */
    private const BASELINE = '/tests/fixtures/course_scope_baseline.json';

    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * The course assistant sends exactly what it sent in 4.17.1.
     */
    public function test_course_assistant_matches_baseline(): void {
        global $CFG;

        // Round-tripped so it compares like for like with the stored JSON.
        $snapshot = json_decode(json_encode($this->snapshot()), true);
        $file = $CFG->dirroot . '/blocks/openaiagent' . self::BASELINE;

        if (getenv('BLOCK_OPENAIAGENT_WRITE_BASELINE')) {
            file_put_contents(
                $file,
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            $this->markTestSkipped('Baseline written to ' . self::BASELINE);
        }

        $this->assertFileExists($file);
        $expected = json_decode(file_get_contents($file), true);

        // Key by key, so a failure names the part that moved.
        $this->assertSame(array_keys($expected), array_keys($snapshot));
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $snapshot[$key], 'Course behaviour changed in: ' . $key);
        }
    }

    /**
     * Build the snapshot for a course block with support escalation configured.
     *
     * @return array
     */
    private function snapshot(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        defaults::install();

        set_config('enabled', 1, 'block_openaiagent');
        set_config('enable_guardrails', 1, 'block_openaiagent');
        set_config('log_messages', 1, 'block_openaiagent');
        set_config('rate_limit_per_user_minute', 0, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');
        set_config('embeddings_provider', 'none', 'block_openaiagent');
        set_config('enable_query_rewrite', 0, 'block_openaiagent');
        set_config('enable_file_search', 1, 'block_openaiagent');
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Baseline Course', 'shortname' => 'BASE']);
        $user = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Baseline']);
        $generator->enrol_user($user->id, $course->id, 'student');
        $block = $generator->create_block('openaiagent', [
            'parentcontextid' => \context_course::instance($course->id)->id,
            'pagetypepattern' => 'course-view-*',
        ]);
        $blockid = (int)$block->id;
        $this->setUser($user);

        $snapshot = [
            'owning_courseid_is_course' => course_config::owning_courseid($blockid, 0) === (int)$course->id,
            'default_tool_names' => defaults::default_tool_names(),
            'enabled_tools_without_rows' => course_config::enabled_tools((int)$course->id, $blockid),
            'function_tools' => orchestrator::function_tools(defaults::default_tool_names()),
        ];

        // Tutor route through the model router.
        $fake = new fake_client();
        $question = 'Can you explain the main idea of the second unit?';
        (new orchestrator($fake))->handle_message((int)$course->id, (int)$user->id, $question, null, $blockid);
        $snapshot['router'] = self::describe($fake->last_router_request());
        $snapshot['tutor'] = self::describe($fake->last_agent_request());

        // Assistant route through the deterministic live-data gate.
        $fake = new fake_client();
        (new orchestrator($fake))->handle_message((int)$course->id, (int)$user->id, 'What is my grade?', null, $blockid);
        $snapshot['assistant'] = self::describe($fake->last_agent_request());

        // Ambiguity route: a contentless message the router cannot place.
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"ambiguous","confidence":0.3,"needs_clarification":true}';
        (new orchestrator($fake))->handle_message((int)$course->id, (int)$user->id, 'hmm', null, $blockid);
        $snapshot['ambiguity'] = self::describe($fake->last_agent_request());

        return $snapshot;
    }

    /**
     * The parts of a request that define behaviour.
     *
     * @param \block_openaiagent\ai\request|null $request Recorded request.
     * @return array|null
     */
    private static function describe(?\block_openaiagent\ai\request $request): ?array {
        if ($request === null) {
            return null;
        }
        return [
            'model' => $request->model,
            'temperature' => $request->temperature,
            'maxtokens' => $request->maxtokens,
            'jsonmode' => $request->jsonmode,
            'reasoningeffort' => $request->reasoningeffort,
            'instructions' => $request->instructions,
            'tools' => $request->tools,
        ];
    }
}
