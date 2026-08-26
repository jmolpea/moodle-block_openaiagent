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
 * Tests for the support escalation eligibility gate.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for support_gate.
 *
 * The single most important assertion in this file is the one that says an
 * ordinary, well-answered question is NOT offered an escalation. Everything
 * else protects the mailbox; that one protects the participant's patience.
 *
 * @covers \block_openaiagent\local\support_gate
 */
final class support_gate_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /** @var \stdClass Enrolled participant. */
    private $user;

    /** @var \stdClass Conversation under test. */
    private $conversation;

    /**
     * Build an enabled course with an enrolled student and a conversation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_max_per_user_day', 3, 'block_openaiagent');
        set_config('support_cooldown_minutes', 10, 'block_openaiagent');
        set_config('support_max_per_course_day', 200, 'block_openaiagent');
        set_config('support_dedupe_hours', 24, 'block_openaiagent');
        set_config('support_offer_cooldown_turns', 5, 'block_openaiagent');
        set_config('log_messages', 1, 'block_openaiagent');

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->conversation = conversation_repository::create($this->user->id, $this->course->id);
    }

    /**
     * Run the gate for this course, user and conversation.
     *
     * @param string $message Message being handled.
     * @return array Gate verdict.
     */
    private function gate(string $message): array {
        $config = course_config::resolve($this->course->id);

        return support_gate::evaluate($config, $this->conversation, $message, $this->user->id, $this->course->id);
    }

    /**
     * Store an assistant reply in the conversation.
     *
     * @param string $text Reply text.
     * @return void
     */
    private function assistant_said(string $text): void {
        conversation_repository::add_message($this->conversation->id, 'assistant', $text);
    }

    /**
     * Create a request row in a given state.
     *
     * @param string $status Status to leave it in.
     * @param int $age How many seconds ago it was created.
     * @return \stdClass
     */
    private function make_request(string $status, int $age = 0): \stdClass {
        global $DB;

        $draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $this->user->id,
            $this->conversation->id,
            'no puedo acceder a la sesion en directo',
            'tecnico'
        );
        $when = time() - $age;
        $DB->update_record('block_openaiagent_supportreq', (object)[
            'id' => $draft->id,
            'status' => $status,
            'timecreated' => $when,
            'timemodified' => $when,
        ]);

        return $draft;
    }

    /**
     * THE test: a normal question the assistant can answer gets no offer.
     */
    public function test_ordinary_question_is_not_offered_escalation(): void {
        $verdict = $this->gate('¿cuándo vence la tarea 2?');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * Nor does a whole conversation of answered questions.
     */
    public function test_answered_conversation_is_never_offered_escalation(): void {
        $questions = [
            '¿dónde está el material de la semana 3?',
            '¿qué nota tengo en el cuestionario?',
            '¿cómo subo el archivo?',
            'gracias',
        ];
        foreach ($questions as $question) {
            conversation_repository::add_message($this->conversation->id, 'user', $question);
            // A reply that resolves the question and points nowhere else. If it
            // mentioned contacting anybody, D5 would rightly open the gate.
            $this->assistant_said('Aquí tienes la respuesta que necesitas.');
            $this->assertFalse($this->gate($question)['allowed'], $question);
        }
    }

    /**
     * Asking for a person works on the very first message.
     */
    public function test_asking_for_a_person_is_offered_immediately(): void {
        $verdict = $this->gate('quiero hablar con una persona de soporte');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_ASKED, $verdict['trigger']);
    }

    /**
     * A fallback reply opens the gate on the next turn.
     *
     * The course has to have a fallback configured, which is what the
     * configuration form seeds the moment anybody saves it. The assertion uses
     * the reply wrapped in ordinary prose, because that is how the model
     * actually delivers it.
     */
    public function test_fallback_opens_the_gate(): void {
        $fallback = 'No encuentro información explícita sobre esto en los documentos oficiales del curso.';
        course_config::save($this->course->id, ['fallbacknoinfo' => $fallback]);
        $this->assistant_said('Vaya. ' . $fallback . ' ¿Puedo ayudarte con otra cosa?');

        $verdict = $this->gate('entonces qué hago');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_FALLBACK, $verdict['trigger']);
    }

    /**
     * With no fallback configured there is nothing to recognise.
     *
     * A course that has never saved its configuration has empty fallback texts,
     * so the assistant was never told to use that wording and matching against
     * it would be matching noise. Such a course reaches the offer through D1, D3
     * or D4 instead. Asserted so the limitation is a decision on the record and
     * not a surprise.
     */
    public function test_fallback_detection_needs_a_configured_fallback(): void {
        $this->assistant_said('No encuentro información explícita sobre esto en los documentos del curso.');

        $verdict = $this->gate('entonces qué hago');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * So does a tool that failed on an earlier turn.
     */
    public function test_tool_failure_opens_the_gate(): void {
        conversation_repository::add_message($this->conversation->id, 'tool', '', [
            'errormessage' => 'moodle.get_course_outline:tool_failed',
        ]);

        $verdict = $this->gate('sigo sin poder ver la semana 3');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_TOOLFAILURE, $verdict['trigger']);
    }

    /**
     * And so does the participant asking the same thing over and over.
     */
    public function test_repetition_opens_the_gate(): void {
        $question = 'no me deja entrar en la sesion en directo de la semana tres';
        conversation_repository::add_message($this->conversation->id, 'user', $question);
        $this->assistant_said('Revisa el enlace del aula.');
        conversation_repository::add_message($this->conversation->id, 'user', $question);

        $verdict = $this->gate($question);

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_REPETITION, $verdict['trigger']);
    }

    /**
     * The assistant sending them to support opens the gate.
     *
     * The case this exists for, taken from a real session: the assistant answers
     * the question, ends with "you will have to contact support", and then
     * offers nothing. Detecting only the configured fallback texts missed it,
     * because that reply was not a fallback: it answered perfectly well and
     * *then* pointed elsewhere.
     */
    public function test_reply_pointing_at_support_opens_the_gate(): void {
        $this->assistant_said(
            'La fecha de entrega venció el 18 de marzo. Si no ves el botón para entregar, '
                . 'tendrás que solicitar autorización al docente o contactar con soporte.'
        );

        $verdict = $this->gate('vale');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_RECOMMENDED, $verdict['trigger']);
    }

    /**
     * Pointing at the FORM opens the gate too.
     *
     * The verbs that go with a form are not the verbs that go with a person, so
     * "utiliza el formulario de Soporte técnico" matched nothing and the
     * participant who answered "sí, quiero que lo reporten" was offered the same
     * thing again instead of a card.
     */
    public function test_pointing_at_the_form_opens_the_gate(): void {
        $replies = [
            'Puedes solicitar una excepción de acceso mediante el formulario de Soporte técnico.',
            'Utiliza el formulario de Soporte técnico; es un canal diferente de este chat.',
            'Para revisarlo, rellena el formulario de Soporte técnico.',
            'Report it through the technical support form.',
        ];
        foreach ($replies as $reply) {
            $this->assistant_said($reply);
            $verdict = $this->gate('vale');
            $this->assertTrue($verdict['allowed'], $reply);
            $this->assertSame(support_gate::TRIGGER_RECOMMENDED, $verdict['trigger'], $reply);
        }
    }

    /**
     * The widened verbs must not fire on an ordinary course sentence. "Solicita"
     * and "tutor" in the same line is not a support recommendation.
     */
    public function test_widened_verbs_do_not_fire_on_course_prose(): void {
        $this->assistant_said(
            'La actividad solicita una calificación del tutor y se completa cuando la recibas. '
                . 'Puedes usar la plantilla de Excel que aparece en la actividad.'
        );

        $verdict = $this->gate('de acuerdo');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * Saying yes to an offer opens the gate.
     *
     * The mirror of the "reply pointed at support" trigger. Without it the model
     * held the drafting tool, was told yes, and answered by making the same
     * offer again: measured on a course run, "sí, quiero que lo reporten" got
     * the offer repeated verbatim and no card.
     */
    public function test_accepting_an_offer_opens_the_gate(): void {
        $offer = 'Como la fecha ya pasó, la acción necesaria es solicitar una excepción. '
            . 'Puedo preparar la solicitud desde este chat para que la confirmes antes de enviarla.';
        foreach (
            ['sí, quiero que lo reporten', 'vale, hazlo', 'adelante por favor',
                  'pero necesito entregarla como sea, ayudame', 'yes please, go ahead'] as $message
        ) {
            $this->assistant_said($offer);
            $verdict = $this->gate($message);
            $this->assertTrue($verdict['allowed'], $message);
            // Either trigger is a correct reading. "hazlo" is also a delegation,
            // and TRIGGER_ASKED is checked first because it has to work on the
            // very first message, with no offer behind it; both open the gate and
            // both are in the server-side backstop list.
            $this->assertContains(
                $verdict['trigger'],
                [support_gate::TRIGGER_ACCEPTED, support_gate::TRIGGER_ASKED],
                $message
            );
        }
    }

    /**
     * Declining it does not, and a new topic after an offer does not either: the
     * card this feature spent three versions learning not to send.
     */
    public function test_offer_not_taken_up_does_not_open_the_gate(): void {
        $offer = 'Puedo preparar la solicitud desde este chat para que la confirmes antes de enviarla.';

        $this->assistant_said($offer);
        $this->assertFalse($this->gate('no, mejor no')['allowed']);

        $this->assistant_said($offer);
        $verdict = $this->gate(
            'oye y otra cosa, donde puedo encontrar la plantilla de excel del analisis '
                . 'FODA que pide la actividad 1.7 de la semana 1'
        );
        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * And an acceptance with no offer behind it is just a word.
     */
    public function test_yes_without_an_offer_opens_nothing(): void {
        $this->assistant_said('Tu progreso muestra 1 actividad completada de 7.');

        $verdict = $this->gate('vale, gracias');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * An ordinary answer that merely mentions support does not.
     */
    public function test_merely_mentioning_support_does_not_open_the_gate(): void {
        $this->assistant_said('Tu consulta ya está con el equipo de soporte, te responderán pronto.');

        $verdict = $this->gate('de acuerdo');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_NOTRIGGER, $verdict['reason']);
    }

    /**
     * Handing the job to the assistant counts as asking for a person.
     *
     * "envía tú la solicitud" names nobody and uses no reaching verb, so the
     * original detector was blind to it even though it is one of the most
     * natural ways to ask for exactly this.
     */
    public function test_delegating_the_request_is_an_explicit_ask(): void {
        foreach (['envía tú la solicitud', 'solicítalo tú', 'hazlo tú', '¿puedes enviarlo tú?'] as $message) {
            $this->assertTrue(support_gate::asks_for_human($message), $message);
        }
    }

    /**
     * Submitting coursework is not delegating a support request.
     */
    public function test_sending_coursework_is_not_an_ask(): void {
        foreach (['¿dónde envío la tarea?', 'quiero enviar mi trabajo', '¿cómo envío mi entrega?'] as $message) {
            $this->assertFalse(support_gate::asks_for_human($message), $message);
        }
    }

    /**
     * Escalation switched off at the site is the end of the matter.
     */
    public function test_disabled_site_denies(): void {
        set_config('support_email_enabled', 0, 'block_openaiagent');

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_DISABLED, $verdict['reason']);
    }

    /**
     * A course that switched it off is not offered it either.
     */
    public function test_disabled_course_denies(): void {
        course_config::save($this->course->id, ['supportmode' => 'off']);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_DISABLED, $verdict['reason']);
    }

    /**
     * Without a destination there is nothing to offer.
     */
    public function test_no_destination_denies(): void {
        set_config('support_email_to', '', 'block_openaiagent');

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_DISABLED, $verdict['reason']);
    }

    /**
     * Someone with no role in the course cannot raise a request.
     */
    public function test_capability_is_required(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $config = course_config::resolve($this->course->id);

        $verdict = support_gate::evaluate(
            $config,
            $this->conversation,
            'quiero hablar con una persona',
            $outsider->id,
            $this->course->id
        );

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_CAPABILITY, $verdict['reason']);
    }

    /**
     * The daily allowance is checked before offering, not after clicking.
     *
     * Offering something that is going to fail on confirmation is the worst
     * possible outcome, so the quota belongs here.
     */
    public function test_exhausted_quota_denies_before_offering(): void {
        set_config('support_max_per_user_day', 1, 'block_openaiagent');
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');
        set_config('support_dedupe_hours', 0, 'block_openaiagent');
        $this->make_request(supportrequest::STATUS_SENT);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_QUOTA, $verdict['reason']);
    }

    /**
     * A failed delivery must not cost the participant an attempt.
     */
    public function test_failed_delivery_does_not_consume_quota(): void {
        set_config('support_max_per_user_day', 1, 'block_openaiagent');
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');
        set_config('support_dedupe_hours', 0, 'block_openaiagent');
        $this->make_request(supportrequest::STATUS_FAILED);

        $this->assertSame(0, supportrequest::count_user_today($this->course->id, $this->user->id));
    }

    /**
     * Two requests in a row are held apart by the cooldown.
     */
    public function test_cooldown_denies(): void {
        set_config('support_dedupe_hours', 0, 'block_openaiagent');
        $this->make_request(supportrequest::STATUS_SENT, 60);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_COOLDOWN, $verdict['reason']);
    }

    /**
     * The course ceiling is what protects the mailbox during an incident.
     */
    public function test_course_ceiling_denies(): void {
        set_config('support_max_per_course_day', 1, 'block_openaiagent');
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');
        set_config('support_dedupe_hours', 0, 'block_openaiagent');

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $conversation = conversation_repository::create($other->id, $this->course->id);
        $draft = supportrequest::create_draft(
            $this->course->id,
            0,
            $other->id,
            $conversation->id,
            'otra incidencia distinta',
            'tecnico'
        );
        supportrequest::set_status((int)$draft->id, supportrequest::STATUS_SENT);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_COURSECEILING, $verdict['reason']);
    }

    /**
     * An unanswered draft blocks a second one in the same conversation.
     */
    public function test_pending_draft_denies(): void {
        $this->make_request(supportrequest::STATUS_DRAFT);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_PENDING, $verdict['reason']);
    }

    /**
     * A second, different problem in the same conversation is not locked out.
     *
     * Reported from a real session: having sent one query, the participant was
     * told "you already have one" with no idea when that would stop being true.
     * The block was keyed to the 24-hour deduplication window, which is a
     * different job -- deduplication decides whether this is the *same* problem
     * again, by content and across conversations. Once the cooldown has passed,
     * a genuinely different issue must be able to reach the support team.
     */
    public function test_a_different_problem_is_not_locked_out_for_the_day(): void {
        set_config('support_cooldown_minutes', 10, 'block_openaiagent');
        set_config('support_dedupe_hours', 24, 'block_openaiagent');

        // Sent well after the cooldown but well inside the dedupe window.
        $this->make_request(supportrequest::STATUS_SENT, 2 * HOURSECS);

        $verdict = $this->gate('quiero hablar con una persona sobre otro problema distinto');

        $this->assertTrue($verdict['allowed']);
    }

    /**
     * Straight after sending one, the cooldown is what holds, and it says so.
     */
    public function test_cooldown_is_the_reason_right_after_sending(): void {
        set_config('support_cooldown_minutes', 10, 'block_openaiagent');
        $this->make_request(supportrequest::STATUS_SENT, 60);

        $verdict = $this->gate('quiero hablar con una persona');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_COOLDOWN, $verdict['reason']);
    }

    /**
     * After a refusal the assistant stays quiet for a few turns.
     */
    public function test_refusal_silences_the_offer(): void {
        $draft = $this->make_request(supportrequest::STATUS_DRAFT);
        supportrequest::set_status((int)$draft->id, supportrequest::STATUS_CANCELLED);
        $config = course_config::resolve($this->course->id);
        $this->assistant_said('De acuerdo. ' . $config['fallbacknoinfo']);

        $verdict = $this->gate('vale, y entonces');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(support_gate::DENIED_REFUSED, $verdict['reason']);
    }

    /**
     * But changing your mind and saying so out loud is always honoured.
     */
    public function test_explicit_request_overrides_the_refusal_silence(): void {
        $draft = $this->make_request(supportrequest::STATUS_DRAFT);
        supportrequest::set_status((int)$draft->id, supportrequest::STATUS_CANCELLED);

        $verdict = $this->gate('me lo he pensado mejor, quiero hablar con una persona');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(support_gate::TRIGGER_ASKED, $verdict['trigger']);
    }

    /**
     * The hard preconditions hold on their own, which is what the drafting tool
     * repeats at execution time.
     */
    public function test_hard_preconditions_are_reusable(): void {
        $config = course_config::resolve($this->course->id);

        $this->assertSame(
            '',
            support_gate::hard_preconditions($config, (int)$this->conversation->id, $this->user->id, $this->course->id)
        );

        set_config('support_email_enabled', 0, 'block_openaiagent');
        $config = course_config::resolve($this->course->id);

        $this->assertSame(
            support_gate::DENIED_DISABLED,
            support_gate::hard_preconditions($config, (int)$this->conversation->id, $this->user->id, $this->course->id)
        );
    }
}
