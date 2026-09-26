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
 * When the support card appears, end to end through the orchestrator.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\defaults;
use block_openaiagent\local\supportrequest;

/**
 * The product rule: the card appears when the participant asks for it, or
 * after the assistant offers to prepare it and they accept. Never on its own.
 *
 * @covers \block_openaiagent\orchestrator
 * @covers \block_openaiagent\local\support_gate
 */
final class support_escalation_flow_test extends \advanced_testcase {
    /**
     * Load the fake client.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * A course with escalation switched on and an enrolled student.
     *
     * @return array [course, user]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        defaults::install();

        set_config('enabled', 1, 'block_openaiagent');
        set_config('log_messages', 1, 'block_openaiagent');
        set_config('rate_limit_per_user_minute', 0, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');
        set_config('embeddings_provider', 'none', 'block_openaiagent');
        set_config('enable_query_rewrite', 0, 'block_openaiagent');
        set_config('enable_file_search', 0, 'block_openaiagent');
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_max_per_user_day', 3, 'block_openaiagent');
        set_config('support_max_per_course_day', 200, 'block_openaiagent');
        set_config('support_cooldown_minutes', 0, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return [$course, $user];
    }

    /**
     * Asking HOW to contact support is a question, not a request to be put through.
     *
     * The assistant answers it; a card nobody asked for must not appear under
     * the answer.
     */
    public function test_asking_how_to_contact_support_gets_no_card(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttext = 'Puedes escribir al equipo de soporte desde el enlace «Soporte técnico» del menú superior.';

        $result = (new orchestrator($fake))->handle_message(
            $course->id,
            $user->id,
            '¿Cómo puedo contactar con soporte técnico?'
        );

        $this->assertSame([], $result['actions'], 'A card appeared although nobody asked for one.');
    }

    /**
     * Asking the assistant to put them through does produce the card at once.
     */
    public function test_asking_to_be_put_through_gets_the_card(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttext = 'De acuerdo, preparo la solicitud para el equipo de soporte.';

        $result = (new orchestrator($fake))->handle_message(
            $course->id,
            $user->id,
            'Quiero hablar con una persona del equipo de soporte, no puedo entregar la tarea.'
        );

        $this->assertCount(1, $result['actions']);
    }

    /**
     * An offer the participant accepts produces the card.
     */
    public function test_accepted_offer_gets_the_card(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $orchestrator = new orchestrator($fake);

        $fake->agenttext = 'No encuentro la causa del error. Si quieres, puedo preparar la solicitud al equipo de soporte.';
        $first = $orchestrator->handle_message($course->id, $user->id, 'La tarea no me deja subir el archivo');
        $this->assertSame([], $first['actions']);

        $fake->agenttext = 'Perfecto.';
        $second = $orchestrator->handle_message($course->id, $user->id, 'sí, por favor', (int)$first['conversationid']);
        $this->assertCount(1, $second['actions']);
    }

    /**
     * A reply that sends the participant to support, on a turn the gate opened
     * for another reason, must offer first: it may not draft on its own.
     */
    public function test_recommending_support_on_a_repeated_question_offers_first(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $orchestrator = new orchestrator($fake);
        $question = 'no me aparece la nota del modulo dos en el libro de calificaciones';

        $fake->agenttext = 'La nota se publica cuando el docente cierra la actividad.';
        $first = $orchestrator->handle_message($course->id, $user->id, $question);
        $cid = (int)$first['conversationid'];
        $orchestrator->handle_message($course->id, $user->id, $question, $cid);

        $fake->agenttext = 'Si sigue sin aparecer, contacta con el equipo de soporte.';
        $third = $orchestrator->handle_message($course->id, $user->id, $question, $cid);

        $this->assertSame([], $third['actions'], 'A card was drafted without offering it first.');
    }

    /**
     * "I want a person" with no problem yet: no empty card, the assistant asks,
     * and the answer to that question produces the card.
     */
    public function test_request_without_a_problem_waits_for_the_problem(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $orchestrator = new orchestrator($fake);

        $fake->summarytext = 'NONE';
        $fake->agenttext = 'Claro. ¿Qué problema tienes, para contárselo al equipo de soporte?';
        $first = $orchestrator->handle_message($course->id, $user->id, 'quiero hablar con una persona');
        $this->assertSame([], $first['actions'], 'A card with nothing to report appeared.');

        $fake->summarytext = 'No puedo abrir el cuestionario del módulo 3: aparece un error al empezar.';
        $fake->agenttext = 'Entendido.';
        $second = $orchestrator->handle_message(
            $course->id,
            $user->id,
            'el cuestionario del módulo 3 me da error al empezar',
            (int)$first['conversationid']
        );
        $this->assertCount(1, $second['actions']);
        $payload = json_decode($second['actions'][0]['payload'], true);
        $this->assertStringStartsWith('No puedo abrir el cuestionario', $payload['summary']);
    }

    /**
     * With the allowance used up the assistant is told why, instead of being
     * told that it can always send a request.
     */
    public function test_blocked_turn_tells_the_model_why(): void {
        [$course, $user] = $this->setup_course();
        set_config('support_max_per_user_day', 1, 'block_openaiagent');
        $conversation = \block_openaiagent\local\conversation_repository::create($user->id, $course->id);
        $sent = supportrequest::create_draft($course->id, 0, $user->id, (int)$conversation->id, 'Algo anterior', 'otro');
        supportrequest::set_status((int)$sent->id, supportrequest::STATUS_SENT);

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttext = 'Hoy ya no puedo preparar otra solicitud.';
        $result = (new orchestrator($fake))->handle_message(
            $course->id,
            $user->id,
            'quiero hablar con soporte otra vez',
            (int)$conversation->id
        );

        $this->assertSame([], $result['actions']);
        $instructions = $fake->last_agent_request()->instructions;
        $this->assertStringContainsString('cannot be prepared from this chat right now', $instructions);
        $this->assertStringContainsString('today\'s limit', $instructions);
        $this->assertStringNotContainsString(defaults::SUPPORT_STATUS_DIRECTIVE, $instructions);
    }

    /**
     * A card left unanswered must not lock the conversation.
     *
     * The participant ignores the card and later asks for a person about a
     * different problem: they must get a card for THAT problem.
     */
    public function test_an_unanswered_card_does_not_lock_the_conversation(): void {
        [$course, $user] = $this->setup_course();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $orchestrator = new orchestrator($fake);

        $fake->agenttext = 'Preparo la solicitud.';
        $first = $orchestrator->handle_message(
            $course->id,
            $user->id,
            'Quiero hablar con soporte: no puedo entrar al curso desde el móvil.'
        );
        $cid = (int)$first['conversationid'];
        $this->assertCount(1, $first['actions']);
        $firstdraft = (int)$first['actions'][0]['id'];

        $second = $orchestrator->handle_message(
            $course->id,
            $user->id,
            'Otra cosa: quiero hablar con soporte, el cuestionario final me da error al enviarlo.',
            $cid
        );

        $this->assertCount(1, $second['actions']);
        $this->assertNotSame(
            $firstdraft,
            (int)$second['actions'][0]['id'],
            'The second problem was answered with the first card.'
        );
        $this->assertSame(1, (int)$GLOBALS['DB']->count_records_select(
            'block_openaiagent_supportreq',
            'conversationid = ? AND status = ?',
            [$cid, supportrequest::STATUS_DRAFT]
        ), 'More than one card is waiting in the same conversation.');
    }
}
