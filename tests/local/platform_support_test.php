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
 * Tests for support escalation and analytics of category and site assistants.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Support gate and analytics rows outside a course.
 *
 * @covers \block_openaiagent\local\support_gate
 * @covers \block_openaiagent\local\analytics
 */
final class platform_support_test extends \advanced_testcase {
    /** @var int Category block id. */
    private int $blockid;

    /** @var \stdClass Participant with no role anywhere. */
    private \stdClass $user;

    /**
     * A category assistant, support switched on with a real address.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        defaults::install();
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');

        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => 'Posgrado']);
        $this->blockid = (int)$generator->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($category->id)->id,
            'pagetypepattern' => '*',
        ])->id;
        $this->user = $generator->create_user();
    }

    /**
     * Preconditions for the participant in a fresh conversation of the category assistant.
     *
     * @return string Empty when escalation may be offered, otherwise the reason.
     */
    private function preconditions(): string {
        $conversation = conversation_repository::create((int)$this->user->id, (int)SITEID, $this->blockid);
        $config = course_config::resolve((int)SITEID, $this->blockid);
        return support_gate::hard_preconditions($config, (int)$conversation->id, (int)$this->user->id, (int)SITEID);
    }

    /**
     * Any logged-in user may reach support from a category assistant; a site can prohibit it.
     */
    public function test_platform_capability(): void {
        global $CFG;
        $this->assertSame('', $this->preconditions());

        $context = \context_block::instance($this->blockid);
        assign_capability(
            'block/openaiagent:requestplatformsupport',
            CAP_PROHIBIT,
            (int)$CFG->defaultuserroleid,
            $context->id,
            true
        );
        $this->assertSame(support_gate::DENIED_CAPABILITY, $this->preconditions());
    }

    /**
     * A destination made only of {course_teachers} reaches nobody outside a course.
     */
    public function test_course_teachers_only_is_off_outside_a_course(): void {
        set_config('support_email_to', '{course_teachers}', 'block_openaiagent');
        $this->assertSame(support_gate::DENIED_DISABLED, $this->preconditions());

        set_config('support_email_to', '{course_teachers}, cau@example.org', 'block_openaiagent');
        $this->assertSame('', $this->preconditions());
    }

    /**
     * A participant with no role anywhere can confirm a draft raised in a category assistant.
     *
     * Before 4.18 the confirmation was checked against the site course, where
     * such a participant holds no role, so the card could never be confirmed.
     */
    public function test_confirm_from_a_category_assistant(): void {
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');
        $conversation = conversation_repository::create((int)$this->user->id, (int)SITEID, $this->blockid);
        $draft = supportrequest::create_draft(
            (int)SITEID,
            $this->blockid,
            (int)$this->user->id,
            (int)$conversation->id,
            'no me llega el certificado del master',
            'acceso'
        );
        $this->setUser($this->user);

        $result = \block_openaiagent\external\confirm_support_request::execute(
            (int)SITEID,
            (int)$draft->id,
            (string)$draft->token,
            true
        );
        $this->assertTrue($result['success']);
        $this->assertSame(supportrequest::STATUS_QUEUED, $result['status']);

        // Someone else cannot use the same route: the draft is not theirs, so
        // the platform checks do not apply and the site-course checks refuse
        // them, exactly as for a draft id that does not exist.
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $this->expectException(\required_capability_exception::class);
        \block_openaiagent\external\confirm_support_request::execute(
            (int)SITEID,
            (int)$draft->id,
            (string)$draft->token,
            true
        );
    }

    /**
     * Each category or site assistant has its own analytics row, with no enrolment figure.
     */
    public function test_analytics_row_per_platform_assistant(): void {
        set_config('log_messages', 1, 'block_openaiagent');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra']);
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id);
        $site = $this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_course::instance(SITEID)->id,
        ]);

        foreach ([[(int)$course->id, 0], [(int)SITEID, $this->blockid], [(int)SITEID, (int)$site->id]] as [$cid, $bid]) {
            $conversation = conversation_repository::create((int)$this->user->id, $cid, $bid);
            conversation_repository::add_message((int)$conversation->id, 'user', 'q');
            conversation_repository::add_message((int)$conversation->id, 'assistant', 'a', ['route' => 'assistant']);
        }
        analytics::build();

        $today = analytics::day_start(time());
        $rows = array_column(analytics::get_course_rows($today, $today), null, 'fullname');

        $this->assertSame(1, $rows['Algebra']->enrolled);
        $this->assertArrayHasKey('Posgrado', $rows);
        $this->assertNull($rows['Posgrado']->enrolled);
        $this->assertSame($this->blockid, $rows['Posgrado']->blockinstanceid);
        $this->assertSame(1, $rows['Posgrado']->questions);
        $this->assertCount(3, $rows);
    }
}
