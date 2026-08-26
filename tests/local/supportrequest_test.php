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
 * Tests for quota accounting and deduplication of support requests.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for supportrequest.
 *
 * These are the counters the anti-spam limits are built on, so what matters is
 * as much what they do NOT count as what they do: unconfirmed drafts, failed
 * deliveries and yesterday's requests must all stay out of today's allowance.
 *
 * @covers \block_openaiagent\local\supportrequest
 */
final class supportrequest_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Participant. */
    private $user;

    /** @var \stdClass Conversation. */
    private $conversation;

    /**
     * Build a course, a participant and a conversation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->conversation = conversation_repository::create($this->user->id, $this->course->id);
    }

    /**
     * Create a request in a given state and age.
     *
     * @param string $status Status to leave it in.
     * @param string $summary Summary text.
     * @param int $age Seconds ago it was created.
     * @param int|null $userid Owner (defaults to the fixture participant).
     * @return \stdClass
     */
    private function make(string $status, string $summary = 'no puedo acceder', int $age = 0, ?int $userid = null): \stdClass {
        global $DB;

        $draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $userid ?? $this->user->id,
            $this->conversation->id,
            $summary,
            'tecnico'
        );
        $when = time() - $age;
        $DB->update_record('block_openaiagent_supportreq', (object)[
            'id' => $draft->id,
            'status' => $status,
            'timecreated' => $when,
            'timemodified' => $when,
        ]);

        return supportrequest::get((int)$draft->id);
    }

    /**
     * A confirmed request counts against the daily allowance.
     */
    public function test_queued_and_sent_count(): void {
        $this->make(supportrequest::STATUS_QUEUED);
        $this->make(supportrequest::STATUS_SENT);

        $this->assertSame(2, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * A draft nobody confirmed does not.
     */
    public function test_drafts_do_not_count(): void {
        $this->make(supportrequest::STATUS_DRAFT);
        $this->make(supportrequest::STATUS_CANCELLED);

        $this->assertSame(0, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * Nor does a delivery that failed: the participant did nothing wrong.
     */
    public function test_failed_delivery_does_not_count(): void {
        $this->make(supportrequest::STATUS_FAILED);

        $this->assertSame(0, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * Yesterday's requests do not eat into today's allowance.
     */
    public function test_yesterday_does_not_count(): void {
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder', DAYSECS + HOURSECS);

        $this->assertSame(0, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * The allowance is per participant, not shared across the course.
     */
    public function test_quota_is_per_participant(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder', 0, $other->id);

        $this->assertSame(0, supportrequest::count_user_today($this->course->id, $this->user->id));
        $this->assertSame(1, supportrequest::count_user_today($this->course->id, $other->id));
    }

    /**
     * The course ceiling counts everybody's requests together: that is what
     * makes it a circuit breaker rather than another per-user limit.
     */
    public function test_course_ceiling_counts_everybody(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $this->make(supportrequest::STATUS_SENT);
        $this->make(supportrequest::STATUS_SENT, 'otra cosa', 0, $other->id);

        $this->assertSame(2, supportrequest::count_course_today($this->course->id));
    }

    /**
     * The cooldown measures from the most recent request.
     */
    public function test_seconds_since_last(): void {
        $this->assertNull(supportrequest::seconds_since_last($this->course->id, $this->user->id));

        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder', 600);
        $this->make(supportrequest::STATUS_SENT, 'otra distinta', 60);

        $since = supportrequest::seconds_since_last($this->course->id, $this->user->id);
        $this->assertNotNull($since);
        $this->assertLessThan(120, $since);
    }

    /**
     * The same complaint reworded is recognised as the same complaint.
     */
    public function test_duplicate_survives_rewording(): void {
        $sent = $this->make(supportrequest::STATUS_SENT, 'No puedo acceder a la sesión en directo.');

        $duplicate = supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('  no puedo acceder a la sesion en directo  '),
            24
        );

        $this->assertNotNull($duplicate);
        $this->assertSame((int)$sent->id, (int)$duplicate->id);
        $this->assertSame($sent->ticketref, $duplicate->ticketref);
    }

    /**
     * A genuinely different problem is not a duplicate.
     */
    public function test_different_problem_is_not_a_duplicate(): void {
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder a la sesion en directo');

        $this->assertNull(supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('no encuentro la rubrica de la tarea final'),
            24
        ));
    }

    /**
     * An unconfirmed draft is not a duplicate of anything.
     */
    public function test_draft_is_not_a_duplicate(): void {
        $this->make(supportrequest::STATUS_DRAFT, 'no puedo acceder');

        $this->assertNull(supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('no puedo acceder'),
            24
        ));
    }

    /**
     * A failed delivery must not stop the participant trying again.
     */
    public function test_failed_request_is_not_a_duplicate(): void {
        $this->make(supportrequest::STATUS_FAILED, 'no puedo acceder');

        $this->assertNull(supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('no puedo acceder'),
            24
        ));
    }

    /**
     * Outside the window it is a new request again.
     */
    public function test_duplicate_window_expires(): void {
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder', 3 * DAYSECS);

        $hash = supportrequest::summary_hash('no puedo acceder');
        $this->assertNull(supportrequest::find_duplicate($this->course->id, $this->user->id, $hash, 24));
        $this->assertNotNull(supportrequest::find_duplicate($this->course->id, $this->user->id, $hash, 24 * 7));
    }

    /**
     * Deduplication never reaches across participants: two people hitting the
     * same problem both deserve an answer.
     */
    public function test_duplicate_is_per_participant(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder', 0, $other->id);

        $this->assertNull(supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('no puedo acceder'),
            24
        ));
    }

    /**
     * A window of zero switches deduplication off.
     */
    public function test_zero_window_disables_deduplication(): void {
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder');

        $this->assertNull(supportrequest::find_duplicate(
            $this->course->id,
            $this->user->id,
            supportrequest::summary_hash('no puedo acceder'),
            0
        ));
    }

    /**
     * An empty summary hashes to nothing and can never match.
     */
    public function test_empty_summary_never_matches(): void {
        $this->make(supportrequest::STATUS_SENT, 'no puedo acceder');

        $this->assertNull(supportrequest::find_duplicate($this->course->id, $this->user->id, '', 24));
    }

    /**
     * Drafts nobody answered are expired, not left pending for ever.
     */
    public function test_expire_drafts(): void {
        global $DB;
        $draft = $this->make(supportrequest::STATUS_DRAFT);
        $DB->set_field('block_openaiagent_supportreq', 'tokenexpiry', time() - 10, ['id' => $draft->id]);

        $this->assertSame(1, supportrequest::expire_drafts());

        $stored = supportrequest::get((int)$draft->id);
        $this->assertSame(supportrequest::STATUS_EXPIRED, $stored->status);
        // The token goes with it: an expired draft must not stay usable.
        $this->assertSame('', $stored->token);
    }

    /**
     * A live draft is left alone.
     */
    public function test_expire_drafts_leaves_live_ones(): void {
        $draft = $this->make(supportrequest::STATUS_DRAFT);

        $this->assertSame(0, supportrequest::expire_drafts());
        $this->assertSame(supportrequest::STATUS_DRAFT, supportrequest::get((int)$draft->id)->status);
    }
}
