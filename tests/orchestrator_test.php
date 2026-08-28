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
 * End-to-end tests for the orchestrator with a scripted client.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\course_config;
use block_openaiagent\local\defaults;
use block_openaiagent\local\rag;

/**
 * Unit tests for the orchestrator.
 *
 * @covers \block_openaiagent\orchestrator
 */
final class orchestrator_test extends \advanced_testcase {
    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * Establish a baseline working configuration with seeded agents.
     *
     * @return \stdClass The created course.
     */
    protected function baseline(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        defaults::install();

        set_config('enabled', 1, 'block_openaiagent');
        set_config('enable_guardrails', 1, 'block_openaiagent');
        set_config('log_messages', 1, 'block_openaiagent');
        set_config('rate_limit_per_user_minute', 0, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');
        // No embeddings key in tests: retrieval uses the lexical fallback.
        set_config('embeddings_provider', 'none', 'block_openaiagent');
        // Rewriting is exercised by its own tests; keep the rest deterministic.
        set_config('enable_query_rewrite', 0, 'block_openaiagent');

        return $this->getDataGenerator()->create_course();
    }

    /**
     * Insert a knowledge-base chunk for a course.
     *
     * @param int $courseid Course id.
     * @param string $content Chunk text.
     * @param bool $citable Whether the chunk is citable.
     * @return void
     */
    private function add_chunk(int $courseid, string $content, bool $citable = true): void {
        global $DB;
        $now = time();
        $DB->insert_record(rag::TABLE, (object) [
            'courseid' => $courseid,
            'contenthash' => sha1($content),
            'filename' => 'notes.pdf',
            'citable' => $citable ? 1 : 0,
            'chunkindex' => 0,
            'content' => $content,
            'embedding' => null,
            'embeddingmodel' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * A high-confidence tutor classification routes to the tutor and grounds the
     * answer in the retrieved knowledge-base excerpts.
     */
    public function test_tutor_route_injects_knowledge_base(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 1, 'block_openaiagent');
        course_config::save($course->id, ['enabled' => 1]);
        $this->add_chunk($course->id, 'Recursion is a technique where a function calls itself.');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95}';
        $fake->agenttext = 'A tutor explanation.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'Explain recursion');

        $this->assertTrue($result['success']);
        $this->assertSame('tutor', $result['route']);
        $this->assertSame('A tutor explanation.', $result['reply']);

        // The tutor instructions carry the retrieved excerpt.
        $request = $fake->last_agent_request();
        $this->assertStringContainsString('Course document excerpts', $request->instructions);
        $this->assertStringContainsString('a function calls itself', $request->instructions);
        $this->assertStringContainsString('notes.pdf', $request->instructions);
    }

    /**
     * With no documents uploaded, the tutor is told to apply the no-information
     * fallback instead of failing or inventing content.
     */
    public function test_tutor_without_documents_gets_noinfo_directive(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 1, 'block_openaiagent');
        course_config::save($course->id, ['enabled' => 1]);

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95}';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'Explain recursion');

        $this->assertTrue($result['success']);
        $request = $fake->last_agent_request();
        $this->assertStringContainsString('No course document excerpts are available', $request->instructions);
    }

    /**
     * A vague tutor question triggers the conditional query rewriter: one extra
     * cheap model call expands the query, and the re-retrieved excerpts ground
     * the tutor.
     */
    public function test_tutor_vague_question_triggers_query_rewrite(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 1, 'block_openaiagent');
        set_config('enable_query_rewrite', 1, 'block_openaiagent');
        course_config::save($course->id, ['enabled' => 1]);
        $this->add_chunk($course->id, 'La fotosíntesis convierte la luz del sol en alimento para la planta.');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95}';
        // The fake returns this for every non-router call: first as the rewritten
        // query, then as the tutor's answer.
        $fake->agenttext = 'fotosíntesis luz del sol alimento planta';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, '¿y eso?');

        $this->assertTrue($result['success']);
        // Router (jsonmode) + rewriter + tutor = three requests.
        $this->assertCount(3, $fake->requests);
        $this->assertStringContainsString('search query', $fake->requests[1]->instructions);
        // The excerpt found via the rewritten query grounds the tutor call.
        $tutorrequest = $fake->last_agent_request();
        $this->assertStringContainsString('fotosíntesis convierte la luz', $tutorrequest->instructions);
    }

    /**
     * An assistant classification routes to the assistant.
     */
    public function test_assistant_route(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';
        $fake->agenttext = 'Your grade is 8.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        $this->assertTrue($result['success']);
        $this->assertSame('assistant', $result['route']);
        $this->assertSame('Your grade is 8.', $result['reply']);
    }

    /**
     * With the tutor disabled, the block is a single-purpose platform assistant:
     * even a clearly conceptual question skips the router and goes to the
     * assistant.
     */
    public function test_tutor_disabled_forces_assistant_route(): void {
        $course = $this->baseline();
        course_config::save($course->id, ['enabled' => 1, 'tutorenabled' => 0, 'assistantenabled' => 1]);

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        // Router would say "tutor", but it must never be consulted.
        $fake->routerjson = '{"intent":"tutor","confidence":0.99}';
        $fake->agenttext = 'A support answer.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'Explain recursion');

        $this->assertTrue($result['success']);
        $this->assertSame('assistant', $result['route']);
        $this->assertNull($fake->last_router_request(), 'Router must be skipped in single-agent mode.');
    }

    /**
     * With the assistant disabled, the block is a single-purpose subject tutor:
     * even a first-person live-data question skips both the router and the
     * deterministic platform gate and goes to the tutor.
     */
    public function test_assistant_disabled_forces_tutor_route(): void {
        $course = $this->baseline();
        course_config::save($course->id, ['enabled' => 1, 'tutorenabled' => 1, 'assistantenabled' => 0]);

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.99}';
        $fake->agenttext = 'A tutor answer.';

        $orchestrator = new orchestrator($fake);
        // A phrasing the platform gate would normally force to the assistant.
        $result = $orchestrator->handle_message($course->id, $user->id, '¿Qué nota tengo en el curso?');

        $this->assertTrue($result['success']);
        $this->assertSame('tutor', $result['route']);
        $this->assertNull($fake->last_router_request(), 'Router must be skipped in single-agent mode.');
    }

    /**
     * The assistant request exposes the enabled Moodle tools as neutral function
     * definitions with provider-safe names and without server-injected params.
     */
    public function test_assistant_request_carries_function_tools(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';

        $orchestrator = new orchestrator($fake);
        $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        $request = $fake->last_agent_request();
        $this->assertNotEmpty($request->tools);

        $names = array_column($request->tools, 'name');
        $this->assertContains('moodle__get_user_grades_summary', $names);
        foreach ($request->tools as $tool) {
            // Provider function-name charset: no dots.
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $tool['name']);
            $properties = (array)($tool['parameters']['properties'] ?? []);
            $this->assertArrayNotHasKey('mcp_session_token', $properties);
            $this->assertArrayNotHasKey('user_id', $properties);
        }
    }

    /**
     * When the model requests a tool, the orchestrator executes it locally and
     * feeds the result back until the model produces the final answer.
     */
    public function test_assistant_tool_loop_executes_locally(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $fake->agenttoolcalls = [
            ['id' => 'call_1', 'name' => 'moodle__get_context', 'arguments' => []],
        ];
        $fake->agenttext = 'You are in the test course.';

        $orchestrator = new orchestrator($fake);
        // First-person grade phrasing takes the deterministic assistant gate,
        // so no router call is involved.
        $result = $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        $this->assertTrue($result['success']);
        $this->assertSame('You are in the test course.', $result['reply']);

        // Two agent calls: one returning the tool call, one with the tool result.
        $this->assertCount(2, $fake->requests);
        $final = $fake->last_agent_request();
        $roles = array_column($final->messages, 'role');
        $this->assertContains('tool', $roles);

        // The tool result actually contains live course data (executed locally).
        $toolmessage = null;
        foreach ($final->messages as $message) {
            if ($message['role'] === 'tool') {
                $toolmessage = $message;
            }
        }
        $this->assertNotNull($toolmessage);
        $this->assertStringContainsString((string)$course->id, $toolmessage['content']);

        // Tokens are aggregated across both round-trips (10+5 then 10+20).
        $this->assertSame(45, $result['tokens']['total']);
    }

    /**
     * A per-course tutor prompt replaces the default instead of extending it.
     *
     * Same contract as the assistant's, which is the point: one prompt per route
     * and one place to look. Appending meant the author was writing a supplement
     * to a prompt they could not read, and the two ended up contradicting each
     * other on answer length, language and use of general knowledge.
     */
    public function test_course_tutor_prompt_replaces_the_default(): void {
        global $DB;
        $course = $this->baseline();

        // Through the repository, which creates the row. set_field() was updating
        // zero rows on a course that has no configuration yet, so the prompt was
        // never stored and the assertions below were measuring the default.
        //
        // "enabled" has to be passed explicitly: a course with no row at all
        // counts as enabled, but the column itself defaults to 0, so inserting a
        // partial row would switch the assistant off and no agent would run.
        course_config::save($course->id, [
            'enabled' => 1,
            'courseprompt' => 'ONLY-COURSE-PROMPT',
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $orchestrator = new orchestrator($fake);
        $orchestrator->handle_message($course->id, $user->id, '¿Qué es el alcance del proyecto?');

        $instructions = $fake->last_agent_request()->instructions;

        $this->assertStringContainsString('ONLY-COURSE-PROMPT', $instructions);
        // The default prompt is gone, not merely pushed down.
        $this->assertStringNotContainsString('You are the official academic tutor', $instructions);
        // ...but the rules a course must not be able to weaken are still there,
        // because they are appended after the course prompt, not inside it.
        $this->assertStringNotContainsString('Course-specific guidance', $instructions);
        $this->assertStringContainsString('Identifiers: NEVER reveal internal numeric', $instructions);
    }

    /**
     * The surname never reaches the model, and the first name comes last.
     *
     * Everything in the instructions leaves the site, so the agents get the
     * minimum that lets them address the participant: a first name. The surname
     * added nothing to any answer and made the person identifiable in a third
     * party's logs -- and it was showing up verbatim in replies.
     *
     * The position matters too: providers cache by prefix, so the per-user block
     * has to sit after the course-wide content. With the name in second position
     * every participant paid full price for the same course prompt and policies.
     */
    public function test_instructions_carry_first_name_only_and_carry_it_last(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Julio',
            'lastname' => 'Molpeceres',
            'email' => 'julio.molpeceres@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $orchestrator = new orchestrator($fake);
        $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        $instructions = $fake->last_agent_request()->instructions;

        $this->assertStringContainsString('Julio', $instructions);
        $this->assertStringNotContainsString('Molpeceres', $instructions);
        $this->assertStringNotContainsString('julio.molpeceres@example.com', $instructions);

        // The identity block is in the last fifth of the prompt: everything
        // before it is identical for every participant and therefore cacheable.
        $position = strpos($instructions, 'Julio');
        $this->assertGreaterThan(strlen($instructions) * 0.8, $position);
    }

    /**
     * A router that says "ambiguous" about a real question is overruled.
     *
     * interpret_intent() collapses a good classification to ambiguous whenever
     * the router's confidence dips, and typos, an opening greeting or a rambling
     * preamble are enough to do that on a perfectly clear question. The turn was
     * then spent asking the participant what they meant. A message that carries a
     * request now falls back to the tutor, which owns both the course answer and
     * the out-of-scope reply.
     */
    public function test_router_ambiguity_is_overruled_for_a_real_question(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"ambiguous","confidence":0.3,"needs_clarification":true}';
        $fake->agenttext = 'Empieza reconociendo algo que hizo bien.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message(
            $course->id,
            $user->id,
            'ola, una consulta rapida xfa, tengo q darle feedback a un compa y no se por donde empiezo'
        );

        $this->assertSame('tutor', $result['route']);
        $this->assertSame('Empieza reconociendo algo que hizo bien.', $result['reply']);
    }

    /**
     * A message that really carries nothing still goes to the ambiguity agent.
     */
    public function test_contentless_message_stays_ambiguous(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"ambiguous","confidence":0.3,"needs_clarification":true}';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'hola');

        $this->assertSame('ambiguous', $result['route']);
    }

    /**
     * A model that never stops calling tools still ends the turn with an answer.
     *
     * Measured on a live course, 30% of assistant turns spent the whole tool
     * budget exploring and returned empty text, which the orchestrator turned
     * into the generic "temporarily unavailable" message -- on exactly the
     * questions that need the most lookups ("am I passing?", "why is week 3
     * locked?"). The budget is now larger, and when it does run out the tools are
     * taken away and the model is asked for prose, so the participant always gets
     * something useful.
     */
    public function test_assistant_forced_answer_when_tool_budget_runs_out(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $fake = new fake_client();
        $fake->alwaystoolcalls = true;
        $fake->agenttoolcalls = [
            ['id' => 'call_1', 'name' => 'moodle__get_context', 'arguments' => []],
        ];
        $fake->agenttext = 'Con lo que he podido consultar, aún no tienes nota final.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        // The participant gets the answer, not the generic unavailable message.
        $this->assertTrue($result['success']);
        $this->assertSame('Con lo que he podido consultar, aún no tienes nota final.', $result['reply']);
        $this->assertStringNotContainsString('temporalmente', $result['reply']);

        // The rescue call is made exactly once, with the tools removed: that is
        // what leaves the model no move except writing the answer.
        $last = $fake->last_agent_request();
        $this->assertSame([], $last->tools);

        // Every earlier agent call did carry tools, so the budget really was spent
        // before the rescue rather than the loop giving up early.
        $withtools = 0;
        foreach ($fake->requests as $request) {
            if (!$request->jsonmode && !empty($request->tools)) {
                $withtools++;
            }
        }
        $this->assertGreaterThan(4, $withtools);

        // Running out of budget is worth a line in the developer log, and the
        // code emits one on purpose. Moodle's PHPUnit fails any test that leaves
        // a debugging() call unacknowledged, so assert it rather than silence it:
        // if the diagnostic ever disappears, this test says so.
        $this->assertDebuggingCalled();
    }

    /**
     * A clear first-person live-data question is force-routed to the assistant by
     * the deterministic gate, without ever consulting the model router -- so it is
     * immune to a stale router prompt, a weak model or a confidence collapse.
     */
    public function test_grade_question_bypasses_router_to_assistant(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        // Even with the model insisting on "tutor", the gate wins.
        $fake->routerjson = '{"intent":"tutor","confidence":0.99}';
        $fake->agenttext = 'Tu nota es 8.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, '¿Cuál es mi nota en el curso?');

        $this->assertTrue($result['success']);
        $this->assertSame('assistant', $result['route']);
        // No router (jsonmode) call was made: the model was never consulted.
        $this->assertNull($fake->last_router_request());
    }

    /**
     * "Pending activities" is also force-routed to the assistant.
     */
    public function test_pending_activities_bypasses_router_to_assistant(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.99}';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, '¿Qué actividades tengo pendientes?');

        $this->assertSame('assistant', $result['route']);
        $this->assertNull($fake->last_router_request());
    }

    /**
     * A conceptual question that merely mentions a grade is NOT force-routed: the
     * model router still decides, so "how is my final grade calculated" reaches the
     * tutor. This proves the conceptual veto in the gate.
     */
    public function test_conceptual_grade_question_uses_model_router(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95}';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, '¿Cómo se calcula mi nota final?');

        $this->assertSame('tutor', $result['route']);
        // The model router WAS consulted (the gate vetoed itself).
        $this->assertNotNull($fake->last_router_request());
    }

    /**
     * The default router runs on a capable model (gpt-4.1-mini), not nano, so the
     * tutor/assistant/ambiguous classification is reliable.
     */
    public function test_router_uses_capable_model_by_default(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9,"needs_clarification":false}';

        $orchestrator = new orchestrator($fake);
        $orchestrator->handle_message($course->id, $user->id, 'What grade do I have?');

        $router = $fake->last_router_request();
        $this->assertNotNull($router);
        $this->assertSame('gpt-4.1-mini', $router->model);
    }

    /**
     * A concrete topic switch is re-classified on its own content, not dragged back
     * to the previous route. A grade question after a tutor turn must reach the
     * assistant, and the router prompt must not carry the old sticky phrasing.
     */
    public function test_topic_switch_is_reclassified(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();

        // First turn establishes a prior "tutor" intent on the conversation.
        $fake->routerjson = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';
        $orchestrator = new orchestrator($fake);
        $first = $orchestrator->handle_message($course->id, $user->id, 'Explain recursion');
        $cid = (int)$first['conversationid'];

        // Second turn on the same conversation is a real topic switch to live data.
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $second = $orchestrator->handle_message($course->id, $user->id, 'What grade do I have?', $cid);

        $this->assertSame('assistant', $second['route']);

        // The follow-up context tells the router to judge on its own content first
        // and no longer uses the old keyword-heavy "IGNORE this context" phrasing.
        $router = $fake->last_router_request();
        $routerinput = (string)$router->messages[0]['content'];
        $this->assertStringContainsString('strictly on its own content', $routerinput);
        $this->assertStringNotContainsString('IGNORE this context', $routerinput);
    }

    /**
     * The sentence stapled under a support card follows the reply, not the site.
     *
     * An English conversation was getting a Spanish closing line because the
     * string resolved against the site default.
     */
    public function test_reply_language_is_detected_for_appended_strings(): void {
        $this->resetAfterTest();
        $method = new \ReflectionMethod(orchestrator::class, 'reply_language');
        $method->setAccessible(true);

        $english = 'Julio, complete the required activities from the previous week first. '
            . 'The next week is currently locked and the course record shows you must '
            . 'complete the individual activity and pass the quiz.';
        $spanish = 'Julio, puedes cambiarla desde tu perfil. Si el campo no se puede editar, '
            . 'revisa que la actividad este disponible y que tus datos esten completos.';

        $this->assertSame('en', $method->invoke(null, $english));
        $this->assertSame('es', $method->invoke(null, $spanish));
        // Too short to place: better to let get_string decide than to guess wrong.
        $this->assertNull($method->invoke(null, 'Ok.'));
    }

    /**
     * Asking for a person reaches the assistant whatever the router says.
     *
     * The support gate lives on the assistant route alone, so a request the
     * router sent to the tutor never reached the one detector built for it. In a
     * leadership course "quiero hablar con una persona del equipo" reads like
     * course content, and the tutor answered by inviting the participant to ask
     * for what they had just asked for.
     */
    public function test_asking_for_a_person_bypasses_the_router(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        // The router would send this to the tutor, and it is ignored.
        $fake->routerjson = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';

        $orchestrator = new orchestrator($fake);
        $messages = [
            'quiero hablar con una persona del equipo',
            'I need to speak to a real person from the support team',
        ];
        foreach ($messages as $message) {
            $result = $orchestrator->handle_message($course->id, $user->id, $message);
            $this->assertSame('assistant', $result['route'], $message);
        }
    }

    /**
     * A collapse to "ambiguous" does not hand the turn to an agent that knows
     * nothing, when the conversation already has a route.
     *
     * The ambiguity agent has no documents, no tools and no access to the user's
     * data. Measured on a course run, a follow-up of "los elementos clave" after
     * a tutor answer came back with invented course content, and accepting the
     * assistant's own offer to check progress came back as "no tengo acceso a tu
     * progreso".
     */
    public function test_short_followup_inherits_the_established_route(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $orchestrator = new orchestrator($fake);

        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $first = $orchestrator->handle_message($course->id, $user->id, 'What grade do I have?');
        $cid = (int)$first['conversationid'];

        // The router collapses: low confidence on a four-word follow-up.
        $fake->routerjson = '{"intent":"assistant","confidence":0.2}';
        $second = $orchestrator->handle_message($course->id, $user->id, 'si, revisalo por favor', $cid);

        $this->assertSame('assistant', $second['route']);
    }

    /**
     * Courtesy is the exception: it stays with the ambiguity agent rather than
     * sending a specialist off to look something up in answer to "gracias".
     */
    public function test_courtesy_still_reaches_the_ambiguity_agent(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $orchestrator = new orchestrator($fake);

        $fake->routerjson = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';
        $first = $orchestrator->handle_message($course->id, $user->id, 'Explain recursion');
        $cid = (int)$first['conversationid'];

        $fake->routerjson = '{"intent":"tutor","confidence":0.2}';
        $second = $orchestrator->handle_message($course->id, $user->id, 'muchas gracias', $cid);

        $this->assertSame('ambiguous', $second['route']);
    }

    /**
     * An ambiguous turn must not erase the route the conversation was on.
     *
     * The stored intent is the only context the router gets, and "ambiguous" is
     * not a place: storing it left the next turn classified from scratch, so one
     * clarifying question bred another and the participant never got back to the
     * assistant -- nor to the support gate, which lives on that route alone.
     */
    public function test_ambiguous_turn_keeps_the_previous_route(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $orchestrator = new orchestrator($fake);

        // Turn 1 establishes a real route.
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $first = $orchestrator->handle_message($course->id, $user->id, 'What grade do I have?');
        $cid = (int)$first['conversationid'];
        $this->assertSame('assistant', $first['route']);

        // Turn 2 falls through to the ambiguity agent. Courtesy, because that is
        // now the only thing that still reaches it once a route is established.
        $fake->routerjson = '{"intent":"assistant","confidence":0.2}';
        $second = $orchestrator->handle_message($course->id, $user->id, 'muchas gracias', $cid);
        $this->assertSame('ambiguous', $second['route']);

        // Turn 3 must still be told where the conversation was.
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $orchestrator->handle_message($course->id, $user->id, 'y ahora?', $cid);

        $routerinput = (string)$fake->last_router_request()->messages[0]['content'];
        $this->assertStringContainsString('which was "assistant"', $routerinput);
    }

    /**
     * A reply that merely points at support does not conjure a card.
     *
     * The gate is shut on this turn: nothing failed, nothing repeated. Inferring
     * "stuck" from the prose used to bolt a confirmation card underneath an
     * answer that had already resolved the question.
     */
    public function test_mentioning_support_does_not_create_a_card(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_max_per_user_day', 3, 'block_openaiagent');
        set_config('support_max_per_course_day', 200, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95,"needs_clarification":false}';
        $fake->agenttext = 'Puedes cambiarla desde tu perfil. Si el campo no se puede '
            . 'editar, contacta con el formulario de Soporte tecnico.';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'Como cambio mi correo?');

        $this->assertSame('assistant', $result['route']);
        $this->assertSame([], $result['actions']);
    }

    /**
     * A low-confidence classification falls through to the ambiguity route.
     */
    public function test_ambiguous_route(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.2}';
        $fake->agenttext = 'Could you clarify?';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'help');

        $this->assertTrue($result['success']);
        $this->assertSame('ambiguous', $result['route']);
    }

    /**
     * The per-course tutor prompt reaches the tutor and nobody else. Courses put
     * a full tutor persona in this field, so leaking it into the assistant or the
     * ambiguity agent gave them a second, contradictory identity (and cost a
     * course-sized prompt on every turn of every route).
     */
    public function test_courseprompt_is_tutor_only(): void {
        $course = $this->baseline();
        set_config('enable_file_search', 0, 'block_openaiagent');
        course_config::save($course->id, [
            'enabled' => 1,
            'courseprompt' => 'You are the Official Academic Tutor, not a technical assistant.',
        ]);
        $user = $this->getDataGenerator()->create_user();

        // Tutor: receives it.
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.95}';
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'Explain recursion');
        $this->assertStringContainsString(
            'Official Academic Tutor',
            $fake->last_agent_request()->instructions
        );

        // Assistant: must not.
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.95}';
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'What is my progress?');
        $this->assertStringNotContainsString(
            'Official Academic Tutor',
            $fake->last_agent_request()->instructions
        );

        // Ambiguity: must not.
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"tutor","confidence":0.2}';
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'help');
        $this->assertStringNotContainsString(
            'Official Academic Tutor',
            $fake->last_agent_request()->instructions
        );
    }

    /**
     * The global max-output-tokens settings actually reach the request.
     *
     * They were rendered on the settings page but never read: the seeded agent
     * record always won, so raising the cap did nothing. On a reasoning model the
     * cap must also cover the reasoning tokens, and one left at its seeded value
     * makes the model spend the whole budget thinking and return empty text.
     */
    public function test_global_max_output_tokens_is_applied(): void {
        $course = $this->baseline();
        set_config('max_output_tokens_assistant', 4000, 'block_openaiagent');
        set_config('max_output_tokens_router', 900, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';

        // Conceptual phrasing on purpose: a first-person data question takes the
        // deterministic assistant gate and never reaches the router, so the
        // router assertion below would read a null request.
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'What is a rubric?');

        $this->assertSame(4000, $fake->last_agent_request()->maxtokens);
        $this->assertSame(900, $fake->last_router_request()->maxtokens);
    }

    /**
     * The reasoning-effort setting reaches every model-facing request, and stays
     * empty (= provider default) when the admin has not chosen one, so upgrading
     * never silently changes how hard a model thinks.
     */
    public function test_reasoning_effort_setting_reaches_the_request(): void {
        $course = $this->baseline();
        $user = $this->getDataGenerator()->create_user();

        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'What is my progress?');
        $this->assertSame('', $fake->last_agent_request()->reasoningeffort);

        set_config('reasoning_effort', 'low', 'block_openaiagent');
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';
        // A conceptual phrasing, deliberately: a first-person data question takes
        // the deterministic assistant gate and never calls the router, so the
        // router assertion below would read a null request.
        (new orchestrator($fake))->handle_message($course->id, $user->id, 'What is a rubric?');
        $this->assertSame('low', $fake->last_agent_request()->reasoningeffort);
        $this->assertSame('low', $fake->last_router_request()->reasoningeffort);
    }

    /**
     * A per-course override still beats the global setting.
     */
    public function test_course_override_beats_global_max_output_tokens(): void {
        $course = $this->baseline();
        set_config('max_output_tokens_assistant', 4000, 'block_openaiagent');
        course_config::save($course->id, ['enabled' => 1, 'maxoutputtokensoverride' => 1500]);

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';

        (new orchestrator($fake))->handle_message($course->id, $user->id, 'What is my progress?');

        $this->assertSame(1500, $fake->last_agent_request()->maxtokens);
    }

    /**
     * The composed instructions are a non-empty string with no null fragments
     * and always carry the language directive for non-router routes.
     */
    public function test_instructions_have_no_nulls(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';

        $orchestrator = new orchestrator($fake);
        $orchestrator->handle_message($course->id, $user->id, 'What is my progress?');

        $request = $fake->last_agent_request();
        $this->assertIsString($request->instructions);
        $this->assertNotSame('', trim($request->instructions));
        $this->assertStringNotContainsString("\n\n\n", $request->instructions);
        $this->assertStringContainsString('Write your ENTIRE reply in', $request->instructions);
        // The identifier-hygiene directive is injected on every non-router turn so
        // the assistant never surfaces internal ids (course id, cmid, etc.).
        $this->assertStringContainsString('NEVER reveal internal numeric identifiers', $request->instructions);
    }

    /**
     * When the plugin is globally disabled, no call is made and an error returns.
     */
    public function test_disabled_plugin_short_circuits(): void {
        $course = $this->baseline();
        set_config('enabled', 0, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'Hello');

        $this->assertFalse($result['success']);
        $this->assertSame('error_assistantdisabled', $result['errorcode']);
        $this->assertCount(0, $fake->requests);
    }

    /**
     * An empty message is blocked by guardrails before any model call.
     */
    public function test_guardrail_blocks_empty_message(): void {
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, '   ');

        $this->assertFalse($result['success']);
        $this->assertSame('error_emptymessage', $result['errorcode']);
        $this->assertCount(0, $fake->requests);
    }

    /**
     * The rate limiter blocks once the per-minute budget is exhausted.
     */
    public function test_rate_limit_blocks(): void {
        $course = $this->baseline();
        set_config('rate_limit_per_user_minute', 1, 'block_openaiagent');

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';

        $orchestrator = new orchestrator($fake);
        $first = $orchestrator->handle_message($course->id, $user->id, 'first');
        $this->assertTrue($first['success']);

        $second = $orchestrator->handle_message($course->id, $user->id, 'second');
        $this->assertFalse($second['success']);
        $this->assertSame('error_ratelimited', $second['errorcode']);
    }

    /**
     * A failed agent call yields a mapped, user-safe error and stores a blank
     * assistant message with the technical error metadata.
     */
    public function test_agent_failure_is_mapped(): void {
        global $DB;
        $course = $this->baseline();

        $user = $this->getDataGenerator()->create_user();
        $fake = new fake_client();
        $fake->routerjson = '{"intent":"assistant","confidence":0.9}';
        $fake->failagent = true;
        $fake->agenterrorcode = 'ratelimited';

        $orchestrator = new orchestrator($fake);
        $result = $orchestrator->handle_message($course->id, $user->id, 'What is my grade?');

        $this->assertFalse($result['success']);
        $this->assertSame('error_ratelimited', $result['errorcode']);

        // A blank assistant message exists carrying the error metadata.
        $messages = $DB->get_records('block_openaiagent_messages', ['role' => 'assistant']);
        $this->assertNotEmpty($messages);
        $stored = reset($messages);
        $this->assertSame('', $stored->content);
        $this->assertNotSame('', $stored->errormessage);
    }
}
