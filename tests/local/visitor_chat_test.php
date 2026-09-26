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
 * Tests for a visitor turn, end to end.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\fake_client;

/**
 * Visitor turns: who gets in, what they can reach, and what stops them.
 *
 * @covers \block_openaiagent\local\visitor_chat
 * @covers \block_openaiagent\local\block_settings
 * @covers \block_openaiagent\local\platform_profile
 * @covers \block_openaiagent\orchestrator
 */
final class visitor_chat_test extends \advanced_testcase {
    /** @var int Category block id. */
    private int $blockid;

    /** @var \core_course_category Category. */
    private \core_course_category $category;

    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * A working plugin and a category assistant open to visitors.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        defaults::install();
        set_config('enabled', 1, 'block_openaiagent');
        set_config('apikey', 'test-key', 'block_openaiagent');
        set_config('embeddings_provider', 'none', 'block_openaiagent');
        set_config('enable_query_rewrite', 0, 'block_openaiagent');
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('forcelogin', 0);

        $generator = $this->getDataGenerator();
        $this->category = $generator->create_category(['name' => 'Posgrado']);
        $this->blockid = (int)$generator->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($this->category->id)->id,
            'pagetypepattern' => '*',
        ])->id;
        $this->open_to_visitors($this->blockid, true);
        $this->setUser(0);
    }

    /**
     * Switch a block's visitor setting.
     *
     * @param int $blockid Block instance id.
     * @param bool $open Whether visitors may use it.
     */
    private function open_to_visitors(int $blockid, bool $open): void {
        global $DB;
        $DB->set_field(
            'block_instances',
            'configdata',
            base64_encode(serialize((object)['visitors' => $open ? 1 : 0])),
            ['id' => $blockid]
        );
    }

    /**
     * Send one visitor message.
     *
     * @param fake_client $fake Fake provider.
     * @param string $message Message.
     * @param string $conversationtoken Conversation token.
     * @param string|null $pagetoken Page token (null = a valid one).
     * @param int|null $blockid Block (null = the category block).
     * @return array
     */
    private function send(
        fake_client $fake,
        string $message,
        string $conversationtoken = '',
        ?string $pagetoken = null,
        ?int $blockid = null
    ): array {
        $blockid = $blockid ?? $this->blockid;
        $pagetoken = $pagetoken ?? visitor_guard::issue_page_token($blockid);
        return visitor_chat::handle($blockid, $message, $conversationtoken, 0, $pagetoken, '', $fake);
    }

    /**
     * A visitor reaches only the public tools, is told they are not logged in, and gets no escalation.
     */
    public function test_visitor_turn(): void {
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $result = $this->send($fake, 'Which courses can I take?');

        $this->assertTrue($result['success']);
        $this->assertSame(40, strlen($result['conversationtoken']));

        $request = $fake->last_agent_request();
        $names = array_column($request->tools, 'name');
        $this->assertEqualsCanonicalizing(
            ['moodle__search_catalog', 'moodle__get_enrolment_options', 'moodle__get_site_access_info'],
            $names
        );
        $this->assertStringContainsString('the person is NOT logged in', $request->instructions);
        $this->assertStringNotContainsString('address the participant as', $request->instructions);
        $this->assertStringNotContainsString('support_request', $request->instructions);
    }

    /**
     * A personal tool forced by the model is refused, whatever the block allows.
     */
    public function test_forced_personal_tool_is_refused(): void {
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttoolcalls = [
            ['id' => 'c1', 'name' => 'moodle__get_my_courses', 'arguments' => []],
            ['id' => 'c2', 'name' => 'moodle__get_course_outline', 'arguments' => []],
            ['id' => 'c3', 'name' => 'moodle__support_request_draft', 'arguments' => ['summary' => 'x']],
        ];
        $this->send($fake, 'Show me my courses');

        $results = array_filter($fake->last_agent_request()->messages, fn($m) => $m['role'] === 'tool');
        $this->assertCount(3, $results);
        foreach ($results as $message) {
            $this->assertStringContainsString('tool_not_available', $message['content']);
        }
    }

    /**
     * The conversation continues only with its own token.
     */
    public function test_conversation_continuity(): void {
        global $DB;
        $fake = new fake_client();
        $first = $this->send($fake, 'Hello, which courses are there?');
        $second = $this->send(new fake_client(), 'And the next one?', $first['conversationtoken']);
        $this->assertSame($first['conversationtoken'], $second['conversationtoken']);
        $this->assertSame(1, $DB->count_records('block_openaiagent_conversations', ['userid' => 0]));

        $stranger = $this->send(new fake_client(), 'Hi', random_string(40));
        $this->assertNotSame($first['conversationtoken'], $stranger['conversationtoken']);
        $this->assertSame(2, $DB->count_records('block_openaiagent_conversations', ['userid' => 0]));
    }

    /**
     * Closed blocks, forced login, missing capability, course blocks and bad tokens stop before the provider.
     */
    public function test_refusals_spend_nothing(): void {
        global $CFG;
        $fake = new fake_client();

        $this->assertSame('error_visitor_session', $this->send($fake, 'Hi', '', 'bad.token.here')['errorcode']);
        $this->assertSame('error_messagetoolong', $this->send($fake, str_repeat('a', 501))['errorcode']);

        set_config('forcelogin', 1);
        $this->assertSame('error_visitor_unavailable', $this->send($fake, 'Hi')['errorcode']);
        set_config('forcelogin', 0);

        $this->open_to_visitors($this->blockid, false);
        $this->assertSame('error_visitor_unavailable', $this->send($fake, 'Hi')['errorcode']);
        $this->open_to_visitors($this->blockid, true);

        assign_capability(
            'block/openaiagent:usepublic',
            CAP_PROHIBIT,
            (int)$CFG->notloggedinroleid,
            \context_block::instance($this->blockid)->id,
            true
        );
        $this->assertSame('error_visitor_unavailable', $this->send($fake, 'Hi')['errorcode']);

        $course = $this->getDataGenerator()->create_course();
        $courseblock = (int)$this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ])->id;
        $this->open_to_visitors($courseblock, true);
        $this->assertSame('error_visitor_unavailable', $this->send($fake, 'Hi', '', null, $courseblock)['errorcode']);

        $this->assertSame([], $fake->requests);
    }

    /**
     * The address limit and the daily ceiling; at the ceiling a fixed answer and no provider call.
     */
    public function test_limits(): void {
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        set_config('visitor_rate_limit_count', 2, 'block_openaiagent');

        $this->assertTrue($this->send(new fake_client(), 'One')['success']);
        $this->assertTrue($this->send(new fake_client(), 'Two')['success']);
        $this->assertSame('error_ratelimited', $this->send(new fake_client(), 'Three')['errorcode']);

        set_config('visitor_rate_limit_count', 0, 'block_openaiagent');
        set_config('visitor_daily_cap', 2, 'block_openaiagent');
        $fake = new fake_client();
        $result = $this->send($fake, 'Four');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/login/index.php', $result['reply']);
        $this->assertSame([], $fake->requests);
        $this->assertNotEmpty($sink->get_messages());
        $sink->close();
    }

    /**
     * A guest session counts as a visitor; logged-in users never go through this path.
     */
    public function test_audience(): void {
        $this->setGuestUser();
        $scope = scope::for_block($this->blockid, (int)guest_user()->id);
        $this->assertTrue(block_settings::open_to_visitors($scope));

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertFalse(block_settings::open_to_visitors(scope::for_block($this->blockid, (int)$user->id)));
    }

    /**
     * Visitor conversations are purged on their own schedule.
     */
    public function test_visitor_retention(): void {
        global $DB;
        $this->send(new fake_client(), 'Hello');
        $DB->set_field('block_openaiagent_conversations', 'timemodified', time() - 2 * DAYSECS, ['userid' => 0]);
        $admin = conversation_repository::create(2, (int)SITEID, $this->blockid);
        $DB->set_field('block_openaiagent_conversations', 'timemodified', time() - 2 * DAYSECS, ['id' => $admin->id]);

        $visitorconversations = $DB->get_fieldset_select('block_openaiagent_conversations', 'id', 'userid = 0');

        $this->expectOutputRegex('/purged 1 visitor conversation/');
        (new \block_openaiagent\task\purge_conversations_task())->execute();

        // The visitor conversation and its messages are gone; the logged-in one stays.
        $this->assertSame(0, $DB->count_records('block_openaiagent_conversations', ['userid' => 0]));
        [$insql, $params] = $DB->get_in_or_equal($visitorconversations);
        $this->assertSame(0, $DB->count_records_select('block_openaiagent_messages', "conversationid $insql", $params));
        $this->assertTrue($DB->record_exists('block_openaiagent_conversations', ['id' => $admin->id]));
    }
}
