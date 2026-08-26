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
 * Privacy tests for the support escalation table.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\privacy;

use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\supportrequest;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

/**
 * Escalations must be exportable and erasable like everything else.
 *
 * They are the one table in the plugin holding an address a support team can
 * write back to, and they outlive the conversation they came from, so a
 * participant erased from the chat could otherwise stay on record here.
 *
 * @covers \block_openaiagent\privacy\provider
 */
final class supportreq_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Participant. */
    private $user;

    /** @var \stdClass Another participant. */
    private $other;

    /**
     * Two participants, each with one request in the same course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();

        foreach ([$this->user, $this->other] as $participant) {
            $conversation = conversation_repository::create($participant->id, $this->course->id);
            $draft = supportrequest::create_draft(
                $this->course->id,
                0,
                $participant->id,
                $conversation->id,
                'no puedo acceder a la sesion',
                'tecnico'
            );
            supportrequest::mark_sent((int)$draft->id, ['cau@example.org']);
        }
    }

    /**
     * The course context is reported for somebody with an escalation.
     */
    public function test_context_is_reported(): void {
        $contexts = provider::get_contexts_for_userid((int)$this->user->id)->get_contextids();

        // Compared loosely: contextlist hands the ids back as they came from the
        // database, which is as strings.
        $this->assertContainsEquals((int)\context_course::instance($this->course->id)->id, $contexts);
    }

    /**
     * Both participants show up in the course context.
     */
    public function test_users_in_context(): void {
        $context = \context_course::instance($this->course->id);
        $userlist = new \core_privacy\local\request\userlist($context, 'block_openaiagent');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertContains((int)$this->user->id, $userids);
        $this->assertContains((int)$this->other->id, $userids);
    }

    /**
     * The export carries what was asked, where it went and how it ended.
     */
    public function test_export_includes_the_requests(): void {
        $context = \context_course::instance($this->course->id);
        $contextlist = new approved_contextlist($this->user, 'block_openaiagent', [$context->id]);

        provider::export_user_data($contextlist);

        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'block_openaiagent'),
            get_string('privacy:path:supportreq', 'block_openaiagent'),
        ]);

        $this->assertNotEmpty($data->supportrequests);
        $this->assertSame('sent', $data->supportrequests[0]['status']);
        $this->assertStringContainsString('cau@example.org', $data->supportrequests[0]['recipients']);
        $this->assertStringContainsString('no puedo acceder', $data->supportrequests[0]['summary']);
    }

    /**
     * Erasing one participant leaves the other one alone.
     */
    public function test_delete_for_one_user(): void {
        global $DB;
        $context = \context_course::instance($this->course->id);

        provider::delete_data_for_user(
            new approved_contextlist($this->user, 'block_openaiagent', [$context->id])
        );

        $this->assertSame(0, $DB->count_records('block_openaiagent_supportreq', ['userid' => $this->user->id]));
        $this->assertSame(1, $DB->count_records('block_openaiagent_supportreq', ['userid' => $this->other->id]));
    }

    /**
     * Erasing a list of users removes exactly those.
     */
    public function test_delete_for_users(): void {
        global $DB;
        $context = \context_course::instance($this->course->id);

        provider::delete_data_for_users(
            new approved_userlist($context, 'block_openaiagent', [(int)$this->other->id])
        );

        $this->assertSame(1, $DB->count_records('block_openaiagent_supportreq', ['userid' => $this->user->id]));
        $this->assertSame(0, $DB->count_records('block_openaiagent_supportreq', ['userid' => $this->other->id]));
    }

    /**
     * Emptying the course context removes every request in it.
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));

        $this->assertSame(0, $DB->count_records('block_openaiagent_supportreq', ['courseid' => $this->course->id]));
    }

    /**
     * The table and the mailbox it sends to are both declared.
     */
    public function test_metadata_declares_the_table_and_the_mailbox(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('block_openaiagent'));

        $tables = [];
        $links = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[] = $item->get_name();
            }
            if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $links[] = $item->get_name();
            }
        }

        $this->assertContains('block_openaiagent_supportreq', $tables);
        // The support mailbox is an external destination for personal data and
        // has to be declared as one.
        $this->assertContains('supportmailbox', $links);
    }
}
