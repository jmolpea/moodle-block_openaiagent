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
 * Tests for composing and delivering the support escalation email.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for support_composer and support_delivery.
 *
 * @covers \block_openaiagent\local\support_composer
 * @covers \block_openaiagent\local\support_delivery
 */
final class support_delivery_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Participant. */
    private $user;

    /** @var \stdClass Conversation. */
    private $conversation;

    /**
     * Build a course with an enrolled participant and the feature switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_email_cc', '', 'block_openaiagent');
        set_config('support_include_transcript', 0, 'block_openaiagent');
        set_config('support_copy_to_user', 0, 'block_openaiagent');
        set_config('log_messages', 1, 'block_openaiagent');

        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Curso de prueba']);
        $this->user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ana',
            'lastname' => 'García',
            'email' => 'ana.garcia@example.net',
        ]);
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->conversation = conversation_repository::create($this->user->id, $this->course->id);
    }

    /**
     * Create a confirmed request ready to be delivered.
     *
     * @param string $summary Incident summary.
     * @param string $category Category.
     * @return \stdClass
     */
    private function queued_request(string $summary = 'No puedo acceder a la sesión.', string $category = 'tecnico'): \stdClass {
        $draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $this->conversation->id,
            $summary,
            $category
        );
        supportrequest::mark_queued((int)$draft->id);

        return supportrequest::get((int)$draft->id);
    }

    /**
     * The message carries the participant's identity, read from the database.
     */
    public function test_body_carries_the_participant_details(): void {
        $request = $this->queued_request();
        $config = course_config::resolve($this->course->id);

        $body = support_composer::body($request, $config);

        $this->assertStringContainsString('Ana', $body);
        $this->assertStringContainsString('García', $body);
        $this->assertStringContainsString('ana.garcia@example.net', $body);
        $this->assertStringContainsString('Curso de prueba', $body);
        $this->assertStringContainsString($request->ticketref, $body);
        $this->assertStringContainsString('No puedo acceder a la sesión.', $body);
        // Nothing left unsubstituted.
        $this->assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $body);
    }

    /**
     * The subject is a single line even when the summary is not.
     */
    public function test_subject_is_flattened(): void {
        set_config('support_subject_template', '[{ticketref}] {summary}', 'block_openaiagent');
        $request = $this->queued_request("Primera linea\nBcc: attacker@example.com");
        $config = course_config::resolve($this->course->id);

        $subject = support_composer::subject($request, $config);

        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringNotContainsString("\r", $subject);
    }

    /**
     * Multilang is resolved with the DEFAULT filter configuration.
     *
     * This is the assertion that matters. Moodle enables the multilang filter
     * for content only; applying it to strings is a separate box that is off by
     * default. Going through format_string() therefore strips the tags without
     * choosing a language on a normal site, and the support team receives
     * "ConsultaQuery" -- the 4.10.1 bug in a new disguise. Note that
     * filter_set_applies_to_strings() is deliberately NOT called here.
     *
     * The syntax is the core one, <span lang="xx" class="multilang">, which is
     * the standard and the one to use in templates. {mlang} belongs to the
     * third-party filter_multilang2 and is handled too when that plugin exists.
     */
    public function test_multilang_is_resolved_with_default_filter_setup(): void {
        filter_set_global_state('multilang', TEXTFILTER_ON);
        \filter_manager::reset_caches();

        set_config(
            'support_subject_template',
            '<span lang="es" class="multilang">Consulta</span>'
                . '<span lang="en" class="multilang">Query</span> {ticketref}',
            'block_openaiagent'
        );

        $request = $this->queued_request();
        $config = course_config::resolve($this->course->id);
        $subject = support_composer::subject($request, $config);

        $this->assertStringNotContainsString('multilang', $subject);
        $this->assertStringNotContainsString('<span', $subject);
        // One language, not both run together.
        $this->assertStringNotContainsString('ConsultaQuery', $subject);
        $this->assertSame(1, preg_match('/^(Consulta|Query) /', $subject));
        $this->assertStringContainsString($request->ticketref, $subject);
    }

    /**
     * A course name carrying multilang is resolved too, not only the templates.
     */
    public function test_multilang_in_the_course_name(): void {
        global $DB;
        filter_set_global_state('multilang', TEXTFILTER_ON);
        \filter_manager::reset_caches();

        $DB->set_field(
            'course',
            'fullname',
            '<span lang="es" class="multilang">Curso</span><span lang="en" class="multilang">Course</span>',
            ['id' => $this->course->id]
        );

        $request = $this->queued_request();
        $body = support_composer::body($request, course_config::resolve($this->course->id));

        $this->assertStringNotContainsString('multilang', $body);
        $this->assertStringNotContainsString('CursoCourse', $body);
    }

    /**
     * Resolving the filters must not flatten the plain-text layout.
     *
     * The body template is aligned by hand, so anything that ran it through an
     * HTML round trip would arrive as a wall of text.
     */
    public function test_rendering_preserves_the_plain_text_layout(): void {
        filter_set_global_state('multilang', TEXTFILTER_ON);
        \filter_manager::reset_caches();

        set_config('support_body_template', "Referencia: {ticketref}\n  Sangrada:   valor\n\nFin", 'block_openaiagent');

        $request = $this->queued_request();
        $body = support_composer::body($request, course_config::resolve($this->course->id));

        $this->assertStringContainsString("\n  Sangrada:   valor", $body);
        $this->assertStringContainsString("\n\nFin", $body);
    }

    /**
     * The transcript only travels when the profile allows it.
     */
    public function test_transcript_is_opt_in(): void {
        conversation_repository::add_message($this->conversation->id, 'user', 'no me deja entrar');
        conversation_repository::add_message($this->conversation->id, 'assistant', 'revisa el enlace');
        $request = $this->queued_request();

        $off = course_config::resolve($this->course->id);
        $this->assertSame('', support_composer::transcript($request, $off));

        set_config('support_include_transcript', 1, 'block_openaiagent');
        set_config('support_transcript_turns', 6, 'block_openaiagent');
        $on = course_config::resolve($this->course->id);
        $transcript = support_composer::transcript($request, $on);

        $this->assertStringContainsString('no me deja entrar', $transcript);
        $this->assertStringContainsString('revisa el enlace', $transcript);
    }

    /**
     * A category rule replaces the general address rather than adding to it.
     */
    public function test_category_routing_replaces_the_destination(): void {
        course_config::save($this->course->id, [
            'enabled' => 1,
            'supportcategorymap' => "tecnico: cau@example.org\nacademico: profes@example.org",
        ]);

        $config = course_config::resolve($this->course->id);

        $academic = support_composer::recipients($this->queued_request('otra cosa', 'academico'), $config);
        $this->assertSame(['profes@example.org'], $academic['to']);

        $technical = support_composer::recipients($this->queued_request('otra mas', 'tecnico'), $config);
        $this->assertSame(['cau@example.org'], $technical['to']);
    }

    /**
     * An unmapped category falls back to the general address.
     */
    public function test_unmapped_category_uses_the_general_address(): void {
        course_config::save($this->course->id, [
            'enabled' => 1,
            'supportcategorymap' => 'academico: profes@example.org',
        ]);
        $config = course_config::resolve($this->course->id);

        $recipients = support_composer::recipients($this->queued_request('otra', 'tecnico'), $config);

        $this->assertSame(['cau@example.org'], $recipients['to']);
    }

    /**
     * The course-contacts token resolves to the people actually teaching it.
     */
    public function test_course_teachers_token_resolves(): void {
        $teacher = $this->getDataGenerator()->create_user(['email' => 'profe@example.org']);
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        set_config('support_email_to', support_mailer::TOKEN_COURSE_TEACHERS, 'block_openaiagent');

        $config = course_config::resolve($this->course->id);
        $recipients = support_composer::recipients($this->queued_request(), $config);

        $this->assertContains('profe@example.org', $recipients['to']);
    }

    /**
     * A teacher whose address is outside the allowed domains is dropped. The
     * token resolves to whatever the course contacts happen to use, so nobody
     * ever typed that address into a form for the save-time check to catch.
     */
    public function test_course_teachers_token_respects_allowed_domains(): void {
        $inside = $this->getDataGenerator()->create_user(['email' => 'profe@example.org']);
        $outside = $this->getDataGenerator()->create_user(['email' => 'profe@gmail.com']);
        $this->getDataGenerator()->enrol_user($inside->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($outside->id, $this->course->id, 'editingteacher');
        set_config('support_email_to', support_mailer::TOKEN_COURSE_TEACHERS, 'block_openaiagent');
        set_config('support_allowed_domains', 'example.org', 'block_openaiagent');

        $config = course_config::resolve($this->course->id);
        $recipients = support_composer::recipients($this->queued_request(), $config);

        $this->assertContains('profe@example.org', $recipients['to']);
        $this->assertNotContains('profe@gmail.com', $recipients['to']);
        $this->assertDebuggingCalled();
    }

    /**
     * Tightening the domain list after a destination was saved must take
     * effect, not leave the stored address grandfathered in.
     */
    public function test_stored_address_outside_the_domains_is_dropped(): void {
        set_config('support_email_to', 'cau@example.org, externo@gmail.com', 'block_openaiagent');
        set_config('support_allowed_domains', 'example.org', 'block_openaiagent');

        $config = course_config::resolve($this->course->id);
        $recipients = support_composer::recipients($this->queued_request(), $config);

        $this->assertSame(['cau@example.org'], $recipients['to']);
        $this->assertDebuggingCalled();
    }

    /**
     * Delivery goes out from the no-reply account, answering the participant.
     */
    public function test_delivery_sets_the_reply_to(): void {
        $request = $this->queued_request();
        // Notifications are redirected as well, or the one announcing the
        // delivery would arrive here as a second email and this count would be
        // measuring two different things at once.
        $notifications = $this->redirectMessages();
        $sink = $this->redirectEmails();

        $this->assertTrue(support_delivery::send($request));

        $messages = $sink->get_messages();
        $sink->close();
        $notifications->close();

        $this->assertCount(1, $messages);
        $this->assertSame('cau@example.org', $messages[0]->to);
        // The participant is reachable by hitting Reply, which is the whole
        // point of not sending as them in the first place.
        $this->assertStringContainsString('ana.garcia@example.net', $messages[0]->header);
    }

    /**
     * A delivered request is recorded as sent, with its addresses and time.
     */
    public function test_successful_delivery_is_recorded(): void {
        $request = $this->queued_request();
        $notifications = $this->redirectMessages();
        $sink = $this->redirectEmails();
        support_delivery::send($request);
        $sink->close();
        $notifications->close();

        $stored = supportrequest::get((int)$request->id);
        $this->assertSame(supportrequest::STATUS_SENT, $stored->status);
        $this->assertGreaterThan(0, (int)$stored->timesent);
        $this->assertStringContainsString('cau@example.org', (string)$stored->recipients);
    }

    /**
     * The participant's copy is sent only when the profile asks for it.
     *
     * Off by default: the notification already tells them the query went out,
     * so the copy is the site's choice to make rather than a second email
     * everybody gets.
     */
    public function test_copy_to_participant(): void {
        set_config('support_copy_to_user', 1, 'block_openaiagent');
        $request = $this->queued_request();

        $notifications = $this->redirectMessages();
        $sink = $this->redirectEmails();
        support_delivery::send($request);
        $messages = $sink->get_messages();
        $sink->close();
        $notifications->close();

        $this->assertCount(2, $messages);
        $recipients = array_map(static fn($m) => $m->to, $messages);
        $this->assertContains('ana.garcia@example.net', $recipients);
    }

    /**
     * With no destination configured the request fails immediately, without
     * burning three retries on something that cannot improve.
     */
    public function test_missing_destination_fails_at_once(): void {
        set_config('support_email_to', '', 'block_openaiagent');
        $request = $this->queued_request();

        $this->assertFalse(support_delivery::send($request));

        $stored = supportrequest::get((int)$request->id);
        $this->assertSame(supportrequest::STATUS_FAILED, $stored->status);
        $this->assertSame(supportrequest::MAX_ATTEMPTS, (int)$stored->attempts);
    }

    /**
     * A refusal is retried, and only given up on once the attempts run out.
     */
    public function test_failed_attempts_are_counted_then_given_up(): void {
        $request = $this->queued_request();

        $this->assertFalse(supportrequest::record_failed_attempt((int)$request->id, 'smtp down'));
        $this->assertSame(supportrequest::STATUS_QUEUED, supportrequest::get((int)$request->id)->status);

        supportrequest::record_failed_attempt((int)$request->id, 'smtp down');
        $exhausted = supportrequest::record_failed_attempt((int)$request->id, 'smtp down');

        $this->assertTrue($exhausted);
        $stored = supportrequest::get((int)$request->id);
        $this->assertSame(supportrequest::STATUS_FAILED, $stored->status);
        $this->assertStringContainsString('smtp down', (string)$stored->errormsg);
    }

    /**
     * A digest carries every request in one message and marks them all sent.
     */
    public function test_digest_groups_the_course_requests(): void {
        $first = $this->queued_request('no puedo acceder a la sesion');
        $second = $this->queued_request('no me carga el cuestionario');

        $notifications = $this->redirectMessages();
        $sink = $this->redirectEmails();
        $this->assertTrue(support_delivery::send_digest($this->course->id, [$first, $second]));
        $messages = $sink->get_messages();
        $sink->close();
        $notifications->close();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('no puedo acceder a la sesion', $messages[0]->body);
        $this->assertStringContainsString('no me carga el cuestionario', $messages[0]->body);

        $this->assertSame(supportrequest::STATUS_SENT, supportrequest::get((int)$first->id)->status);
        $this->assertSame(supportrequest::STATUS_SENT, supportrequest::get((int)$second->id)->status);
    }

    /**
     * The ad-hoc task refuses to send something that is no longer queued.
     */
    public function test_task_ignores_a_request_that_moved_on(): void {
        $request = $this->queued_request();
        supportrequest::mark_cancelled((int)$request->id);

        $task = new \block_openaiagent\task\send_support_request_task();
        $task->set_custom_data((object)['requestid' => (int)$request->id]);

        $sink = $this->redirectEmails();
        $task->execute();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(0, $messages);
    }
}
