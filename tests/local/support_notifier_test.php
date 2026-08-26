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
 * Tests for closing the loop with the participant.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the participant notifications and the escalation reporting.
 *
 * @covers \block_openaiagent\local\support_notifier
 * @covers \block_openaiagent\local\analytics
 */
final class support_notifier_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Participant. */
    private $user;

    /** @var \stdClass Conversation. */
    private $conversation;

    /**
     * Build a course with the feature switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_copy_to_user', 0, 'block_openaiagent');
        set_config('support_include_transcript', 0, 'block_openaiagent');

        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Curso de prueba']);
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->conversation = conversation_repository::create($this->user->id, $this->course->id);
    }

    /**
     * Create a request in a given state.
     *
     * @param string $status Status to leave it in.
     * @return \stdClass
     */
    private function request(string $status = 'queued'): \stdClass {
        $draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $this->conversation->id,
            'no puedo acceder a la sesion',
            'tecnico'
        );
        supportrequest::set_status((int)$draft->id, $status);

        return supportrequest::get((int)$draft->id);
    }

    /**
     * Delivering a request tells the participant it went out.
     *
     * This is the half of the promise the chat cannot keep: by the time the mail
     * server accepts the message they have very likely closed the window.
     */
    public function test_delivery_notifies_the_participant(): void {
        $request = $this->request();
        $emails = $this->redirectEmails();
        $sink = $this->redirectMessages();

        support_delivery::send($request);

        $messages = $sink->get_messages();
        $sink->close();
        $emails->close();

        $this->assertCount(1, $messages);
        $this->assertSame((int)$this->user->id, (int)$messages[0]->useridto);
        $this->assertSame('supportrequest', $messages[0]->eventtype);
        $this->assertStringContainsString($request->ticketref, $messages[0]->fullmessage);
        $this->assertStringContainsString('Curso de prueba', $messages[0]->fullmessage);
    }

    /**
     * The delivery also leaves a line in the conversation.
     *
     * The notification reaches them wherever they are, but the chat is where
     * they look first, and it must not still be saying the request is waiting to
     * be confirmed once it has gone out.
     */
    public function test_delivery_is_written_into_the_conversation(): void {
        $request = $this->request();
        $emails = $this->redirectEmails();
        $sink = $this->redirectMessages();

        support_delivery::send($request);

        $sink->close();
        $emails->close();

        $last = conversation_repository::last_assistant_message((int)$this->conversation->id);
        $this->assertStringContainsString($request->ticketref, $last);
        $this->assertSame(
            get_string('support_chat_sent', 'block_openaiagent', (string)$request->ticketref),
            $last
        );
    }

    /**
     * A definitive failure is announced too, with somewhere else to go.
     *
     * Silence would leave somebody waiting days for an answer to a message that
     * never left.
     */
    public function test_permanent_failure_notifies_the_participant(): void {
        set_config('support_email_to', '', 'block_openaiagent');
        set_config('support_url', 'https://support.example.org/', 'block_openaiagent');
        $request = $this->request();

        $sink = $this->redirectMessages();
        support_delivery::send($request);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString($request->ticketref, $messages[0]->fullmessage);
        $this->assertStringContainsString('https://support.example.org/', $messages[0]->fullmessage);
    }

    /**
     * A retryable failure says nothing yet: the request may still go out.
     */
    public function test_a_retryable_failure_is_not_announced(): void {
        $request = $this->request();

        $sink = $this->redirectMessages();
        $exhausted = supportrequest::record_failed_attempt((int)$request->id, 'smtp down');
        if ($exhausted) {
            support_notifier::failed($request);
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertFalse($exhausted);
        $this->assertCount(0, $messages);
    }

    /**
     * The escalation ratio counts conversations, not requests.
     */
    public function test_support_summary_counts_and_ratio(): void {
        $this->request('sent');
        $this->request('failed');
        $this->request('cancelled');

        // A second conversation with nothing escalated, so the ratio is not 100%.
        conversation_repository::create($this->user->id, $this->course->id);

        $summary = analytics::get_support_summary(time() - HOURSECS, time() + HOURSECS);

        $this->assertSame(3, $summary->total);
        $this->assertSame(1, $summary->sent);
        $this->assertSame(1, $summary->failed);
        $this->assertSame(1, $summary->cancelled);
        $this->assertSame(2, $summary->conversations);
        // One of the two conversations produced a request that went somewhere.
        $this->assertSame(1, $summary->escalated);
        $this->assertSame(50.0, $summary->ratio);
    }

    /**
     * The audit list answers "was this participant's query really sent?".
     */
    public function test_support_rows_for_the_audit_view(): void {
        $request = $this->request('sent');

        $rows = analytics::get_support_rows(time() - HOURSECS, time() + HOURSECS, 10);

        $this->assertCount(1, $rows);
        $this->assertSame($request->ticketref, $rows[0]['reference']);
        $this->assertSame('Curso de prueba', $rows[0]['course']);
        $this->assertSame(fullname($this->user), $rows[0]['participant']);
        $this->assertSame('sent', $rows[0]['status']);
    }

    /**
     * An offer the participant never confirmed must pull the confirmation rate
     * down. Leaving it out was what made a page with one pending request out of
     * three report that everything had been escalated.
     */
    public function test_unconfirmed_offers_lower_the_confirmation_rate(): void {
        $this->request('sent');
        $this->request('sent');
        $this->request('draft');

        $summary = analytics::get_support_summary(time() - HOURSECS, time() + HOURSECS);

        $this->assertSame(3, $summary->total);
        $this->assertSame(2, $summary->sent);
        $this->assertSame(1, $summary->pending);
        $this->assertSame(2, $summary->confirmed);
        $this->assertSame(66.7, $summary->confirmedratio);
    }

    /**
     * A confirmed request that bounced still counts as confirmed: the
     * participant did their part, the mail server is a separate story.
     */
    public function test_a_failed_delivery_still_counts_as_confirmed(): void {
        $this->request('sent');
        $this->request('failed');

        $summary = analytics::get_support_summary(time() - HOURSECS, time() + HOURSECS);

        $this->assertSame(2, $summary->confirmed);
        $this->assertSame(100.0, $summary->confirmedratio);
    }

    /**
     * The share of conversations must never exceed 100%. It used to, because an
     * escalation raised inside the window counted against a denominator of
     * conversations *started* inside it, and the conversation could be older.
     */
    public function test_conversation_ratio_cannot_exceed_one_hundred(): void {
        global $DB;

        $this->request('sent');
        // Push the conversation itself out of the window, keeping the request in.
        $DB->set_field(
            'block_openaiagent_conversations',
            'timecreated',
            time() - (30 * DAYSECS),
            ['id' => $this->conversation->id]
        );

        $summary = analytics::get_support_summary(time() - HOURSECS, time() + HOURSECS);

        $this->assertSame(0, $summary->conversations);
        $this->assertSame(0.0, $summary->ratio);
        // The request itself is still counted; only the ratio drops it.
        $this->assertSame(1, $summary->total);
    }

    /**
     * The summary scoped to a course counts only that course, on both sides of
     * every ratio. This is the branch that builds the escalated-conversations
     * clause against an aliased column, and nothing exercised it before.
     */
    public function test_support_summary_scoped_to_a_course(): void {
        $this->request('sent');

        // A second course with its own conversation and its own escalation.
        $other = $this->getDataGenerator()->create_course();
        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otheruser->id, $other->id, 'student');
        $otherconv = conversation_repository::create($otheruser->id, $other->id);
        $draft = supportrequest::create_draft(
            $other->id,
            0,
            $otheruser->id,
            $otherconv->id,
            'otro curso, otro problema',
            'tecnico'
        );
        supportrequest::set_status((int)$draft->id, 'sent');

        $from = time() - HOURSECS;
        $to = time() + HOURSECS;

        $everything = analytics::get_support_summary($from, $to);
        $this->assertSame(2, $everything->total);
        $this->assertSame(2, $everything->escalated);
        $this->assertSame(2, $everything->conversations);

        $mine = analytics::get_support_summary($from, $to, [(int)$this->course->id]);
        $this->assertSame(1, $mine->total);
        $this->assertSame(1, $mine->sent);
        $this->assertSame(1, $mine->escalated);
        $this->assertSame(1, $mine->conversations);
        $this->assertSame(100.0, $mine->ratio);

        $theirs = analytics::get_support_summary($from, $to, [(int)$other->id]);
        $this->assertSame(1, $theirs->total);
        $this->assertSame(1, $theirs->escalated);
    }

    /**
     * The report filters by status.
     */
    public function test_support_rows_filter_by_status(): void {
        $this->request('sent');
        $this->request('draft');

        $from = time() - HOURSECS;
        $to = time() + HOURSECS;

        $sent = analytics::get_support_rows($from, $to, 10, ['status' => 'sent']);
        $this->assertCount(1, $sent);
        $this->assertSame('sent', $sent[0]['status']);

        $this->assertSame(1, analytics::count_support_rows($from, $to, ['status' => 'draft']));
        $this->assertSame(2, analytics::count_support_rows($from, $to));
    }

    /**
     * The search box matches the reference, not only the participant, because
     * that is what an administrator has in front of them.
     */
    public function test_support_rows_search_matches_the_reference(): void {
        $request = $this->request('sent');

        $rows = analytics::get_support_rows(
            time() - HOURSECS,
            time() + HOURSECS,
            10,
            ['name' => $request->ticketref]
        );

        $this->assertCount(1, $rows);
        $this->assertSame($request->ticketref, $rows[0]['reference']);
    }

    /**
     * Paging returns disjoint pages rather than the same rows twice.
     */
    public function test_support_rows_paging(): void {
        $this->request('sent');
        $this->request('sent');
        $this->request('sent');

        $from = time() - HOURSECS;
        $to = time() + HOURSECS;

        $this->assertSame(3, analytics::count_support_rows($from, $to));

        $first = analytics::get_support_rows($from, $to, 2, [], 0);
        $second = analytics::get_support_rows($from, $to, 2, [], 2);

        $this->assertCount(2, $first);
        $this->assertCount(1, $second);
        $this->assertEmpty(array_intersect(
            array_column($first, 'reference'),
            array_column($second, 'reference')
        ));
    }

    /**
     * The reference prefix is whatever the institution's help desk recognises.
     */
    public function test_reference_prefix_is_configurable(): void {
        set_config('support_reference_prefix', 'CAU', 'block_openaiagent');

        $request = $this->request('draft');

        $this->assertStringStartsWith('CAU-', $request->ticketref);
    }

    /**
     * A prefix of punctuation is not a prefix. Falling back beats emitting a
     * reference that will not survive a mail subject or a search box.
     */
    public function test_reference_prefix_falls_back_when_unusable(): void {
        set_config('support_reference_prefix', '///', 'block_openaiagent');

        $this->assertSame(
            supportrequest::REFERENCE_PREFIX_DEFAULT,
            supportrequest::reference_prefix()
        );
    }

    /**
     * Requests outside the window are not reported.
     */
    public function test_support_rows_respect_the_window(): void {
        global $DB;
        $request = $this->request('sent');
        $DB->set_field('block_openaiagent_supportreq', 'timecreated', time() - (5 * DAYSECS), ['id' => $request->id]);

        $this->assertCount(0, analytics::get_support_rows(time() - HOURSECS, time() + HOURSECS, 10));
    }
}
