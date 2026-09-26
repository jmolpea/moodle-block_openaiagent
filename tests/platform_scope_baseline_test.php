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
 * Characterisation test: what a category assistant sends for a logged-in user.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\defaults;
use block_openaiagent\local\scope;

/**
 * Pins the logged-in category assistant to its 4.18.0 behaviour.
 *
 * Same idea as the course baseline: visitor support is built next to it and
 * must not change what a logged-in participant's assistant sends to the model.
 * Covers a category page and the same block shown inside an enrolled course.
 *
 * A failure means logged-in behaviour changed. To regenerate after an agreed
 * change, run once with BLOCK_OPENAIAGENT_WRITE_BASELINE=1.
 *
 * @covers \block_openaiagent\orchestrator
 * @covers \block_openaiagent\local\platform_profile
 */
final class platform_scope_baseline_test extends \advanced_testcase {
    /** @var string Baseline file, relative to the plugin directory. */
    private const BASELINE = '/tests/fixtures/platform_scope_baseline.json';

    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * The logged-in category assistant sends exactly what it sent in 4.18.0.
     */
    public function test_category_assistant_matches_baseline(): void {
        global $CFG;

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
        $this->assertSame(array_keys($expected), array_keys($snapshot));
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $snapshot[$key], 'Logged-in category behaviour changed in: ' . $key);
        }
    }

    /**
     * Build the snapshot.
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
        $category = $generator->create_category(['name' => 'Baseline Category']);
        $course = $generator->create_course(['fullname' => 'Baseline Course', 'category' => $category->id]);
        $user = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Baseline']);
        $generator->enrol_user($user->id, $course->id, 'student');
        $blockid = (int)$generator->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($category->id)->id,
            'pagetypepattern' => '*',
        ])->id;
        $this->setUser($user);

        $turn = function (string $routerjson, string $message, int $pagecourseid) use ($user, $blockid): fake_client {
            $fake = new fake_client();
            $fake->routerjson = $routerjson;
            $scope = scope::for_block($blockid, (int)$user->id, $pagecourseid);
            (new orchestrator($fake))->handle_message((int)SITEID, (int)$user->id, $message, null, $blockid, $scope);
            return $fake;
        };
        $assistant = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $tutor = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';

        $snapshot = [];
        $fake = $turn($tutor, 'How do I request my certificate?', 0);
        $snapshot['router'] = self::describe($fake->last_router_request());
        $snapshot['tutor'] = self::describe($fake->last_agent_request());
        $snapshot['assistant'] = self::describe($turn($assistant, 'Which courses am I in?', 0)->last_agent_request());
        $snapshot['assistant_in_course'] = self::describe(
            $turn($assistant, 'Which courses am I in?', (int)$course->id)->last_agent_request()
        );
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
            // Ids depend on the test database, not on behaviour.
            'instructions' => preg_replace('/course id \d+/', 'course id N', $request->instructions),
            'tools' => $request->tools,
            'messagecount' => count($request->messages),
        ];
    }
}
