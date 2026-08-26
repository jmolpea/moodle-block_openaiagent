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
 * Tests for the support confirmation web service.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\course_config;
use block_openaiagent\local\support_action;
use block_openaiagent\local\supportrequest;

/**
 * Unit tests for confirm_support_request.
 *
 * Nothing here checks that an email goes out, because at this point nothing can
 * send one. What is checked is the half that matters for safety: that only the
 * owner, holding a live token, can move a draft out of the draft state.
 *
 * @covers \block_openaiagent\external\confirm_support_request
 */
final class confirm_support_request_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Enrolled participant. */
    private $user;

    /** @var \stdClass Conversation under test. */
    private $conversation;

    /** @var \stdClass Draft awaiting confirmation. */
    private $draft;

    /**
     * Build an enabled course with a participant and a pending draft.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_max_per_user_day', 3, 'block_openaiagent');
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');
        set_config('support_max_per_course_day', 200, 'block_openaiagent');
        set_config('support_dedupe_hours', 24, 'block_openaiagent');

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->conversation = conversation_repository::create($this->user->id, $this->course->id);
        $this->draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $this->conversation->id,
            'no puedo acceder a la sesion en directo',
            'tecnico'
        );
        $this->setUser($this->user);
    }

    /**
     * Confirming leaves the request queued and says so honestly.
     */
    public function test_confirm_queues_the_request(): void {
        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame(supportrequest::STATUS_QUEUED, $result['status']);
        $this->assertSame($this->draft->ticketref, $result['reference']);
        $this->assertStringContainsString($this->draft->ticketref, $result['message']);

        $stored = supportrequest::get((int)$this->draft->id);
        $this->assertSame(supportrequest::STATUS_QUEUED, $stored->status);
        // The token is spent, so the same click cannot be replayed.
        $this->assertSame('', $stored->token);
    }

    /**
     * The participant is told the query is registered, never that it was sent.
     *
     * At this point it has not left. Saying otherwise would be the one lie this
     * feature must never tell.
     */
    public function test_confirmation_does_not_claim_delivery(): void {
        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        // Asserted against the string itself rather than by sniffing for words,
        // so the test says the same thing in every language the block ships.
        $this->assertSame(supportrequest::STATUS_QUEUED, $result['status']);
        $this->assertNotSame(supportrequest::STATUS_SENT, $result['status']);
        $this->assertSame(
            get_string('support_confirm_queued', 'block_openaiagent', $this->draft->ticketref),
            $result['message']
        );
    }

    /**
     * Declining discards the draft without sending anything.
     */
    public function test_cancel_discards_the_draft(): void {
        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            false
        );

        $this->assertTrue($result['success']);
        $this->assertSame(supportrequest::STATUS_CANCELLED, $result['status']);
        $this->assertSame(
            supportrequest::STATUS_CANCELLED,
            supportrequest::get((int)$this->draft->id)->status
        );
    }

    /**
     * The same token cannot be used twice.
     */
    public function test_token_is_single_use(): void {
        confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $second = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertFalse($second['success']);
        $this->assertSame('unavailable', $second['status']);
    }

    /**
     * A wrong token is refused even by the rightful owner.
     */
    public function test_wrong_token_is_refused(): void {
        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            str_repeat('0', 64),
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame(supportrequest::STATUS_DRAFT, supportrequest::get((int)$this->draft->id)->status);
    }

    /**
     * Another participant cannot confirm somebody else's draft, even knowing the
     * id and the token.
     */
    public function test_another_participant_cannot_confirm(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $this->setUser($other);

        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame(supportrequest::STATUS_DRAFT, supportrequest::get((int)$this->draft->id)->status);
    }

    /**
     * An expired draft cannot be confirmed.
     */
    public function test_expired_draft_is_refused(): void {
        global $DB;
        $DB->set_field('block_openaiagent_supportreq', 'tokenexpiry', time() - 10, ['id' => $this->draft->id]);

        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertFalse($result['success']);
    }

    /**
     * A confirmation naming a different course is refused.
     */
    public function test_course_mismatch_is_refused(): void {
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->user->id, $other->id, 'student');

        $result = confirm_support_request::execute(
            $other->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame(supportrequest::STATUS_DRAFT, supportrequest::get((int)$this->draft->id)->status);
    }

    /**
     * A ceiling reached between drawing the card and clicking it is honoured,
     * and the participant is pointed at the support form instead.
     */
    public function test_ceiling_reached_after_the_card_was_drawn(): void {
        set_config('support_max_per_course_day', 1, 'block_openaiagent');
        set_config('support_url', 'https://support.example.org/', 'block_openaiagent');
        set_config('support_dedupe_hours', 0, 'block_openaiagent');

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $conversation = conversation_repository::create($other->id, $this->course->id);
        $consumed = supportrequest::create_draft(
            $this->course->id,
            0,
            $other->id,
            $conversation->id,
            'otra incidencia',
            'tecnico'
        );
        supportrequest::set_status((int)$consumed->id, supportrequest::STATUS_SENT);

        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('https://support.example.org/', $result['message']);
        $this->assertSame(
            supportrequest::STATUS_CANCELLED,
            supportrequest::get((int)$this->draft->id)->status
        );
    }

    /**
     * A request already with the support team is not sent twice, and the
     * participant is given the reference of the one that is.
     */
    public function test_duplicate_returns_the_existing_reference(): void {
        // Same complaint, sent earlier from another conversation and reworded
        // slightly, which is exactly how duplicates arrive in practice.
        $earlier = conversation_repository::create($this->user->id, $this->course->id);
        $sent = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $earlier->id,
            'No puedo acceder a la sesión en directo.',
            'tecnico'
        );
        supportrequest::set_status((int)$sent->id, supportrequest::STATUS_SENT);

        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('duplicate', $result['status']);
        $this->assertSame($sent->ticketref, $result['reference']);
        $this->assertStringContainsString($sent->ticketref, $result['message']);

        // The second one is discarded rather than queued: one ticket, one issue.
        $this->assertSame(
            supportrequest::STATUS_CANCELLED,
            supportrequest::get((int)$this->draft->id)->status
        );
        $this->assertSame(1, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * A different problem raised right after the first one still goes through.
     */
    public function test_a_different_problem_is_not_treated_as_duplicate(): void {
        $earlier = conversation_repository::create($this->user->id, $this->course->id);
        $sent = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $earlier->id,
            'no encuentro la rubrica de la tarea final',
            'academico'
        );
        supportrequest::set_status((int)$sent->id, supportrequest::STATUS_SENT);

        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame(supportrequest::STATUS_QUEUED, $result['status']);
    }

    /**
     * What the participant is told is written into the conversation.
     *
     * Reported from a real session: after confirming and after the email had
     * actually gone out, reopening the chat still ended on the model's "you
     * still have to confirm this in the card". The confirmation reply was only
     * ever returned to the browser, never stored, so a reload lost it and left
     * the conversation ending on a sentence that had stopped being true.
     */
    public function test_confirmation_is_written_into_the_conversation(): void {
        $result = confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $last = conversation_repository::last_assistant_message((int)$this->conversation->id);

        $this->assertSame($result['message'], $last);
        $this->assertStringContainsString($this->draft->ticketref, $last);
    }

    /**
     * Declining is recorded too, for the same reason.
     */
    public function test_cancellation_is_written_into_the_conversation(): void {
        confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            false
        );

        $this->assertSame(
            get_string('support_confirm_cancelled', 'block_openaiagent'),
            conversation_repository::last_assistant_message((int)$this->conversation->id)
        );
    }

    /**
     * The pending card survives closing and reopening the chat.
     */
    public function test_pending_action_is_offered_again(): void {
        $config = course_config::resolve($this->course->id);
        $actions = support_action::pending((int)$this->conversation->id, $config);

        $this->assertCount(1, $actions);
        $this->assertSame(support_action::TYPE_CONFIRM, $actions[0]['type']);
        $this->assertSame((int)$this->draft->id, $actions[0]['id']);

        $payload = json_decode($actions[0]['payload'], true);
        $this->assertSame((string)$this->draft->token, $payload['token']);
        $this->assertStringContainsString('sesion en directo', $payload['summary']);
        $this->assertNotEmpty($payload['privacynotice']);
    }

    /**
     * Once answered, the card is gone.
     */
    public function test_answered_draft_offers_no_action(): void {
        confirm_support_request::execute(
            $this->course->id,
            (int)$this->draft->id,
            (string)$this->draft->token,
            true
        );

        $config = course_config::resolve($this->course->id);

        $this->assertSame([], support_action::pending((int)$this->conversation->id, $config));
    }
}
