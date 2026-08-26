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
 * Central orchestrator: guardrails, intent routing and agent execution.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\ai\client_base;
use block_openaiagent\ai\factory;
use block_openaiagent\ai\request;
use block_openaiagent\ai\response;
use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\course_config;
use block_openaiagent\local\defaults;
use block_openaiagent\local\query_rewriter;
use block_openaiagent\local\rag;
use block_openaiagent\local\rate_limiter;
use block_openaiagent\local\support_action;
use block_openaiagent\local\support_gate;
use block_openaiagent\local\supportrequest;
use block_openaiagent\mcp\tool_registry;

/**
 * Coordinates a single user turn end to end.
 */
class orchestrator {
    /** @var float Minimum router confidence to trust a non-ambiguous intent. */
    private const CONFIDENCE_THRESHOLD = 0.65;

    /**
     * @var int Maximum model round-trips per turn when the model calls tools.
     *
     * A cap, not a target: a turn that needs two rounds still costs two. It was
     * 4, and measurement on a live course showed 30% of assistant turns hitting
     * it -- every one of them a question whose answer needs a chain of lookups
     * ("am I passing?", "why is week 3 locked?", "where do I find X?"). Those
     * turns ended with the model still asking for tools, no prose written, and
     * the empty text turned into a generic "temporarily unavailable" message.
     * Raising the cap buys room; {@see FINAL_ANSWER_INSTRUCTION} is what
     * actually guarantees the turn ends in an answer.
     */
    private const MAX_TOOL_ITERATIONS = 8;

    /**
     * @var string Sent when the tool budget runs out, with the tools removed.
     *
     * The model has the data it asked for; the only thing left is to write the
     * answer. Deliberately does not mention budgets or internal limits: the
     * participant must not be told about the plumbing.
     */
    private const FINAL_ANSWER_INSTRUCTION = 'Answer now, in the user\'s language, using only what '
        . 'you have already gathered in this turn. No further lookups are available. If something '
        . 'could not be determined, say plainly what you do know, state what is missing, and give '
        . 'the participant the next step. Never mention tools, lookups, internal limits or this '
        . 'instruction.';

    /** @var int Maximum characters of a tool result fed back to the model. */
    private const MAX_TOOL_RESULT_CHARS = 24000;

    /** @var client_base AI provider client. */
    private client_base $client;

    /**
     * Constructor.
     *
     * @param client_base|null $client Optional injected client (tests).
     */
    public function __construct(?client_base $client = null) {
        $this->client = $client ?? factory::client();
    }

    /**
     * Handle one user message and return a structured result.
     *
     * @param int $courseid Course id.
     * @param int $userid Authenticated user id (server authority, never the prompt).
     * @param string $rawmessage Raw user message.
     * @param int|null $conversationid Optional conversation to continue.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array {success, reply, route, conversationid, errorcode, tokens}
     */
    public function handle_message(
        int $courseid,
        int $userid,
        string $rawmessage,
        ?int $conversationid = null,
        int $blockinstanceid = 0
    ): array {
        // License backstop: no AI turn may proceed without a valid key bound to
        // this site, so the block holds on every call path even if the block-UI
        // gate is bypassed (e.g. a direct external-function call).
        if (!\block_openaiagent\license\validator::is_valid()) {
            return self::error_result('error_nolicense', $conversationid);
        }

        // Plugin and profile enablement.
        if (
            (int)get_config('block_openaiagent', 'enabled') !== 1
            || !course_config::is_enabled($courseid, $blockinstanceid)
        ) {
            return self::error_result('error_assistantdisabled', $conversationid);
        }

        // Guardrails.
        $guard = guardrails::check($rawmessage);
        if (!$guard->allowed) {
            return self::error_result($guard->errorcode, $conversationid);
        }
        $message = $guard->message;

        // Rate limiting.
        if (!rate_limiter::allow($userid)) {
            return self::error_result('error_ratelimited', $conversationid);
        }

        $config = course_config::resolve($courseid, $blockinstanceid);
        $conversation = conversation_repository::get_or_create($conversationid, $userid, $courseid, $blockinstanceid);

        // Per-course agent toggles. Disabling one content agent turns the block
        // into a single-purpose bot: the router and ambiguity agents are skipped
        // and every message goes straight to the remaining agent. With both
        // disabled (only possible by bypassing the form validation) fall back to
        // full routing rather than going mute.
        $tutoron = !empty($config['tutorenabled']);
        $assistanton = !empty($config['assistantenabled']);
        if (!$tutoron && !$assistanton) {
            $tutoron = true;
            $assistanton = true;
        }

        // Persist the user message.
        conversation_repository::add_message($conversation->id, 'user', $message);
        rate_limiter::record($userid);

        // Deterministic assessment-integrity gate. A clearly multiple-choice or
        // true/false question must never reach an agent that could reveal the
        // answer, no matter how the model would route or behave. This is the
        // hard backstop; the tutor prompt is only the soft second layer.
        $evalblock = trim((string)$config['fallbackevaluationblock']);
        if ($evalblock !== '' && self::looks_like_quiz($message)) {
            conversation_repository::add_message($conversation->id, 'assistant', $evalblock, [
                'route' => 'tutor',
                'agentid' => 0,
            ]);
            conversation_repository::update($conversation, [
                'currentintent' => 'tutor',
                'lastuserrequest' => (int)get_config('block_openaiagent', 'log_messages') === 1 ? $message : '',
            ]);
            return [
                'success' => true,
                'reply' => $evalblock,
                'route' => 'tutor',
                'conversationid' => (int)$conversation->id,
                'errorcode' => '',
                'actions' => [],
                'tokens' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
            ];
        }

        // Deterministic platform-data gate. A clearly first-person question about
        // the user's own live LMS data (grades, pending work, deadlines, access)
        // must reach the course assistant even if the model router would mislabel
        // it. This is the assistant-side counterpart to the quiz gate above and is
        // the hard guarantee for the routes the model most often gets wrong; the
        // router prompt is only the soft second layer. Conceptual phrasings
        // ("explain how the grade is calculated") are vetoed so they still reach the
        // tutor.
        // Asking to be put through to a person is the same kind of guarantee. The
        // detector is already deterministic, but it lives inside the support gate,
        // and the support gate only runs on the assistant route -- so a request the
        // router happened to send to the tutor never reached it at all. Measured on
        // a leadership course, where "quiero hablar con una persona del equipo" and
        // "I need to speak to a real person from the support team" both read as
        // course content, the tutor answered by inviting the participant to ask for
        // exactly what they had just asked for. The one path that has to work on
        // the first message cannot depend on the model router.
        if (
            $assistanton && $tutoron
                && (self::looks_like_platform_query($message) || support_gate::asks_for_human($message))
        ) {
            $intent = 'assistant';
            $result = $this->run_assistant($config, $message, $conversation, $userid, $courseid);
        } else if (!$tutoron) {
            // Single-agent mode: support/platform bot only.
            $intent = 'assistant';
            $result = $this->run_assistant($config, $message, $conversation, $userid, $courseid);
        } else if (!$assistanton) {
            // Single-agent mode: subject-matter tutor only.
            $intent = 'tutor';
            $result = $this->run_tutor($config, $message, $conversation, $courseid, $blockinstanceid);
        } else {
            // Classify intent with the model router.
            $intent = $this->classify_intent($config, $message, $conversation);

            // The "ambiguous" route answers the participant's question with
            // another question, so it has to be reserved for messages that
            // genuinely carry nothing. It was being reached far more often:
            // interpret_intent() collapses a perfectly good classification to
            // ambiguous whenever the router's own confidence dips below the
            // threshold, and typos, an opening greeting or a rambling preamble
            // are enough to do that on a completely clear question ("ola, una
            // consulta rapida xfa, tengo q darle feedback a un compa..."). It
            // also swallowed off-topic requests, so the out-of-scope reply never
            // fired: asking for restaurant recommendations got a clarifying
            // question back instead of a polite decline.
            //
            // When the message does carry a request, fall back to the tutor: it
            // owns both the course answer and the out-of-scope message, so the
            // worst case is a polite decline rather than a wasted turn. Same
            // reasoning as the deterministic live-data gate above -- a decision
            // that can be made without the model router should not depend on it.
            // Before that, a conversation that already has a route keeps it. The
            // ambiguity agent has no documents, no tools and no access to this
            // user's data, so it is the worst possible destination for a
            // follow-up: measured on a course run, "los elementos clave" after a
            // negotiation answer came back with five invented elements, and "sí,
            // revísalo por favor" -- accepting the assistant's own offer to check
            // the progress it had just read -- came back as "no tengo acceso a tu
            // progreso o calificaciones". Both are collapses to ambiguous on a
            // conversation whose route was never in doubt.
            //
            // Courtesy is the exception, and the reason this is not simply folded
            // into the rule below: thanks and goodbyes are the one thing the
            // ambiguity agent handles better than either specialist, which would
            // answer "gracias" by looking something up.
            if ($intent === 'ambiguous') {
                $priorintent = trim((string)$conversation->currentintent);
                if (
                    in_array($priorintent, ['tutor', 'assistant'], true)
                        && !self::is_courtesy($message)
                ) {
                    $intent = $priorintent;
                }
            }

            if ($intent === 'ambiguous' && self::carries_a_request($message)) {
                $intent = 'tutor';
            }

            // Route.
            switch ($intent) {
                case 'tutor':
                    $result = $this->run_tutor($config, $message, $conversation, $courseid, $blockinstanceid);
                    break;
                case 'assistant':
                    $result = $this->run_assistant($config, $message, $conversation, $userid, $courseid);
                    break;
                default:
                    $intent = 'ambiguous';
                    $result = $this->run_ambiguity($config, $message, $conversation);
                    break;
            }
        }

        $result['route'] = $intent;
        $result['conversationid'] = (int)$conversation->id;
        // Always present, so the client never has to guess whether the key
        // exists. Only the assistant route ever fills it.
        $result['actions'] = $intent === 'assistant'
            ? support_action::pending((int)$conversation->id, $config)
            : [];

        // Persist conversation state and assistant message.
        //
        // An ambiguous turn does NOT overwrite the remembered route. The stored
        // intent exists to tell the router where a contentless follow-up belongs,
        // and "ambiguous" is not a place: storing it means the next turn is
        // classified with no context at all, so one clarifying question tends to
        // produce another. Keeping the last route the conversation actually had
        // is what lets "ya lo hice" find its way back to the assistant -- and
        // with it, the support gate, which lives on that route alone.
        $remembered = $intent === 'ambiguous'
            ? (string)$conversation->currentintent
            : $intent;
        conversation_repository::update($conversation, [
            'currentintent' => $remembered,
            'lastuserrequest' => (int)get_config('block_openaiagent', 'log_messages') === 1 ? $message : '',
        ]);

        return $result;
    }

    /**
     * Run the router agent and resolve a safe intent.
     *
     * @param array $config Effective course config.
     * @param string $message User message.
     * @param \stdClass $conversation Conversation record.
     * @return string tutor|assistant|ambiguous
     */
    private function classify_intent(array $config, string $message, \stdClass $conversation): string {
        $agent = $config['agents']['router'];
        if ($agent === null) {
            return 'ambiguous';
        }

        // Give the router the previous turn's intent so contentless follow-ups
        // ("why?", "ok", "tell me more") inherit the right route instead of being
        // classified in isolation. This hint is deliberately narrow: it applies
        // ONLY to messages that carry no question of their own. Any message with a
        // concrete request must be classified on its own merits, so a real topic
        // switch (e.g. "what grade do I have?" after a tutor turn) is not dragged
        // back to the previous route.
        $contextline = '';
        $priorintent = trim((string)$conversation->currentintent);
        if (in_array($priorintent, ['tutor', 'assistant'], true)) {
            // Lead with "classify on its own content" (primacy) and name the prior
            // route only once, at the end. Repeating the route keyword here makes a
            // small router model sticky: it tends to keep echoing the previous route
            // instead of re-judging a genuine topic switch (e.g. a grade question
            // after a tutor turn).
            $contextline = "\n\nClassify this message strictly on its own content. "
                . "Only if it is a contentless follow-up that carries no question or request "
                . "of its own (e.g. \"why?\", \"ok\", \"and?\", \"tell me more\", \"explain that\") "
                . "should you reuse the previous turn's route, which was \"{$priorintent}\".";
        }

        // Some providers' JSON output modes require the word "json" to appear in
        // the input itself (instructions alone are not scanned), so wrap the user
        // message in an explicit JSON directive.
        $routerinput = "Classify the following user message and respond with JSON only:"
            . $contextline . "\n\nUser message: " . $message;

        // A per-course router prompt overrides the default agent prompt, the same
        // way the assistant prompt does. This opens alternative routing policies
        // per course without code changes.
        $baseprompt = $config['routerprompt'] !== '' ? $config['routerprompt'] : $agent->baseprompt;

        $request = $this->build_request(
            $agent,
            $this->compose_instructions($baseprompt, $config, 'router', $conversation),
            'router',
            $config
        );
        $request->jsonmode = true;
        $request->add_user_message($routerinput);

        $response = $this->client->complete($request);
        $resolved = $response->success ? self::interpret_intent($response->text) : 'ambiguous';

        // The router runs on every routed turn and is billed like any other call,
        // so its usage is recorded even though it produces no visible message.
        conversation_repository::record_internal_usage(
            (int)$conversation->id,
            'router',
            $request->model,
            $response
        );

        // Make the router decision observable. With developer debugging on, this
        // surfaces the model actually used, the raw JSON it returned and the intent
        // we resolved -- the three facts you need to tell a misrouting from a stale
        // prompt, a weak model or a confidence collapse.
        if ((int)get_config('block_openaiagent', 'debugmode') === 1) {
            $detail = $response->success ? $response->text : ('error:' . $response->errorcode);
            debugging('block_openaiagent router decision: model=' . $request->model
                . '; intent=' . $resolved . '; raw=' . $detail, DEBUG_DEVELOPER);
        }

        return $resolved;
    }

    /**
     * Parse and normalize the router's JSON output into a safe intent.
     *
     * @param string $json Raw router output.
     * @return string tutor|assistant|ambiguous
     */
    public static function interpret_intent(string $json): string {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded) || !isset($decoded['intent'])) {
            return 'ambiguous';
        }

        $intent = strtolower(trim((string)$decoded['intent']));

        // Map legacy Spanish intents.
        $map = [
            'asistente' => 'assistant',
            'ambigua' => 'ambiguous',
            'ambiguo' => 'ambiguous',
            'tutor' => 'tutor',
        ];
        if (isset($map[$intent])) {
            $intent = $map[$intent];
        }

        if (!in_array($intent, ['tutor', 'assistant', 'ambiguous'], true)) {
            return 'ambiguous';
        }

        // A missing confidence field means "trusted": a minimal, intent-only prompt
        // ({"intent":"assistant"}) must route directly. Only an explicit low
        // confidence collapses to ambiguous, so the threshold never silently
        // swallows a good classification.
        $confidence = isset($decoded['confidence']) ? (float)$decoded['confidence'] : 1.0;
        if ($intent !== 'ambiguous' && $confidence < self::CONFIDENCE_THRESHOLD) {
            return 'ambiguous';
        }

        $needsclarification = !empty($decoded['needs_clarification']);
        if ($needsclarification && $intent !== 'ambiguous' && $confidence < 0.8) {
            return 'ambiguous';
        }

        return $intent;
    }

    /**
     * Deterministically detect a multiple-choice or true/false quiz question.
     *
     * Keys on the answer-option structure plus an explicit correctness/selection
     * cue, never on a bare interrogative like "cuál es...", so ordinary
     * conceptual questions (including ones that merely enumerate a/b/c items)
     * are not misclassified as quizzes. Cues cover Spanish, English and
     * Portuguese. Designed to err towards letting genuine questions through.
     *
     * @param string $message User message.
     * @return bool
     */
    private static function looks_like_quiz(string $message): bool {
        $text = \core_text::strtolower($message);

        // Count contiguous lettered answer options starting at "a": a) b) c)...
        // (also "a." form, optionally parenthesised). Requiring the run to start
        // at "a" avoids matching stray markers inside ordinary prose.
        $run = 0;
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $letter) {
            if (preg_match('/(?:^|[\s(\[])' . $letter . '[\)\.]\s/u', $text)) {
                $run++;
            } else {
                break;
            }
        }

        // An explicit true/false prompt is evaluative on its own.
        if (preg_match('/(verdadero\s*(?:o|\/)\s*falso|true\s*(?:or|\/)\s*false|certo\s*(?:ou|\/)\s*errado)/u', $text)) {
            return true;
        }

        // An option list alone is not enough (it could be a conceptual
        // enumeration), so require an explicit correctness/selection cue.
        if ($run >= 2) {
            $cue = '/(correcta|correctas|incorrecta|incorrectas|correcto|correctos'
                . '|correct|incorrect'
                . '|correta|corretas|incorreta'
                . '|assinale'
                . '|selecciona la|seleccione la|elige la|elija la|marca la|marque la'
                . '|select the correct|choose the correct|pick the correct)/u';
            if (preg_match($cue, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the message actually ask for something?
     *
     * Deliberately crude, because it only has to separate "hola" / "ok" / "no
     * entiendo" from a real request. A question mark (either kind) settles it;
     * otherwise a message of five words or more is carrying content, however
     * badly spelled. Everything shorter and unpunctuated stays ambiguous, which
     * is where a bare greeting or a contentless follow-up belongs.
     *
     * @param string $message User message.
     * @return bool True when the message carries something to answer.
     */
    private static function carries_a_request(string $message): bool {
        $message = trim($message);
        if ($message === '') {
            return false;
        }
        if (strpos($message, '?') !== false || strpos($message, '¿') !== false) {
            return true;
        }
        return count(preg_split('/\s+/u', $message) ?: []) >= 5;
    }

    /**
     * Whether the message is only courtesy: thanks, an acknowledgement or a
     * goodbye, with nothing else in it.
     *
     * Kept deliberately narrow. It decides one thing only -- that a conversation
     * with an established route should NOT inherit it for this turn -- and the
     * cost of a false positive is a "thanks" answered by a specialist looking
     * something up. So it requires the whole message to be courtesy: a handful of
     * words, and no question mark, because "gracias, y cuándo cierra?" is a
     * question wearing a thank-you.
     *
     * Spanish, English and Portuguese, the three languages the rest of the
     * plugin's detectors handle.
     *
     * @param string $message User message.
     * @return bool
     */
    private static function is_courtesy(string $message): bool {
        $text = \core_text::strtolower(trim($message));
        if ($text === '' || strpos($text, '?') !== false || strpos($text, '¿') !== false) {
            return false;
        }
        if (count(preg_split('/\s+/u', $text) ?: []) > 6) {
            return false;
        }

        $courtesy = '/\b(gracias|muchas gracias|mil gracias|te lo agradezco|perfecto|genial|'
            . 'estupendo|vale|ok|okay|listo|entendido|de acuerdo|adios|adiós|hasta luego|'
            . 'buen dia|buen día|saludos|'
            . 'thanks|thank you|thx|great|perfect|got it|understood|bye|goodbye|'
            . 'obrigado|obrigada|valeu|entendi|tchau|ate logo|até logo)\b/u';

        if (!preg_match($courtesy, $text)) {
            return false;
        }

        // A courtesy word plus a verb of asking is not courtesy ("gracias, revisa
        // otra vez"), so require that nothing else of substance is present.
        $rest = trim(preg_replace($courtesy, ' ', $text));
        $rest = trim(preg_replace('/\b(por favor|please|por gentileza|y|and|e|muy|very|really|'
            . 'much|mucho|mucha|muchas|tanto|todo|tudo|so|'
            . 'me|te|lo|la|el|un|una|eso|esto|ya|tambien|también)\b|[[:punct:]]/u', ' ', $rest));

        return trim($rest) === '';
    }

    /**
     * Deterministically detect an unambiguous request for the user's own live
     * LMS data, which belongs to the course assistant.
     *
     * This is the hard backstop the model router cannot override. It keys on
     * first-person ownership of course data ("my grade", "what do I have
     * pending") or an inherent live-data lookup (deadlines, access), in Spanish,
     * English and Portuguese. Purely conceptual phrasings are vetoed first so a
     * question that merely mentions a platform noun ("explain how the grade is
     * calculated", "what is a rubric") still reaches the tutor. Designed to err
     * towards letting the model decide when the signal is not strong.
     *
     * @param string $message User message.
     * @return bool
     */
    private static function looks_like_platform_query(string $message): bool {
        $text = \core_text::strtolower($message);

        // Veto: explanatory/conceptual intent goes to the tutor even when a
        // platform noun is present.
        //
        // The interrogatives carry a negative lookahead for a first-person
        // possessive. "What is a rubric?" is conceptual and belongs to the
        // tutor, but "What is my grade?" is the plainest possible request for
        // the user's own live data, and the veto used to swallow it: the
        // deterministic gate exists precisely so that question never depends on
        // the model router, and the most natural English phrasing of it was the
        // one phrasing that bypassed the gate. Only the interrogatives are
        // guarded; "how is my grade calculated" still reaches the tutor, which
        // is the behaviour this veto was written for.
        $conceptual = '/(qu[eé] es (?!mi |mis )|qu[eé] son (?!mi |mis )|defin|expl[ií]c|por qu[eé]'
            . '|c[oó]mo funciona'
            . '|c[oó]mo se calcula|c[oó]mo calcular|para qu[eé] sirve|diferencia entre'
            . '|what is (?!my )|what are (?!my )|define|explain|how does|how is .{0,40}calculated'
            . '|why does|meaning of'
            . '|o que [eé] (?!minha |minhas |meu |meus )|explique|por que |como funciona)/u';
        if (preg_match($conceptual, $text)) {
            return false;
        }

        // First-person ownership of course data.
        $personal = '/(mi nota|mis notas|mi calificaci[oó]n|mis calificaciones|mi progreso|mi avance'
            . '|qu[eé] nota tengo|qu[eé] nota saqu[eé]|cu[aá]nto saqu[eé]'
            . '|tengo pendiente|tengo pendientes|me falta|me faltan|tengo que entregar'
            . '|he entregado|mi entrega|mis entregas|mis intentos|cu[aá]ntos intentos'
            . '|actividades pendientes|tareas pendientes|he aprobado|tengo aprobad'
            . '|my grade|my grades|my mark|my marks|my progress|my score|my attempts'
            . '|do i have pending|i have to submit|i have to hand in'
            . '|minha nota|minhas notas|meu progresso|tenho pendente|minhas entregas)/u';
        if (preg_match($personal, $text)) {
            return true;
        }

        // Inherent live-data lookups that need no first-person marker.
        $inherent = '/(fecha de entrega|fecha l[ií]mite|cu[aá]ndo vence|cu[aá]ndo cierra|cu[aá]ndo abre'
            . '|fecha de cierre|fecha de apertura|fecha de habilitaci[oó]n'
            . '|no puedo acceder|no me deja entrar|est[aá] bloqueado|aparece bloqueado'
            . '|due date|deadline|when is .{0,40}due|can.?t access|cannot access|is locked'
            . '|data de entrega|prazo de entrega|n[aã]o consigo acessar)/u';
        if (preg_match($inherent, $text)) {
            return true;
        }

        return false;
    }

    /**
     * Run the course tutor (grounded in the local course knowledge base).
     *
     * @param array $config Effective config.
     * @param string $message User message.
     * @param \stdClass $conversation Conversation record.
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array Partial result.
     */
    private function run_tutor(
        array $config,
        string $message,
        \stdClass $conversation,
        int $courseid,
        int $blockinstanceid = 0
    ): array {
        $agent = $config['agents']['tutor'];
        if ($agent === null) {
            return self::error_result('error_openai_failed', (int)$conversation->id);
        }

        // A per-course tutor prompt REPLACES the default one, exactly as the
        // assistant's does. It used to be appended instead, which meant the
        // course author was writing a supplement to a prompt they could not see:
        // the two ended up repeating the same policy in different words and, in
        // the courses we have measured, contradicting each other outright on
        // answer length, on language, and on whether general knowledge may be
        // used at all. Replacing gives one authority per route and one place to
        // look. The rules that must survive a replacement do not live here: they
        // are appended after the course prompt by rag::format_context() and by
        // compose_instructions(), where a course cannot weaken them.
        $baseprompt = $config['courseprompt'] !== '' ? $config['courseprompt'] : $agent->baseprompt;
        $instructions = $this->compose_instructions($baseprompt, $config, 'tutor', $conversation, $message);

        if ((int)get_config('block_openaiagent', 'enable_file_search') === 1) {
            $maxresults = (int)get_config('block_openaiagent', 'file_search_max_results');
            $limit = $maxresults > 0 ? $maxresults : 8;
            $retrievalquery = self::build_retrieval_query($message, $conversation);
            $diagnosed = rag::retrieve_diagnosed($courseid, $retrievalquery, $limit, $blockinstanceid);
            $chunks = $diagnosed['chunks'];

            // Second pass on the topic words alone. A long, politely padded
            // question embeds mostly as its padding, so the section that answers
            // it can drop out of the results even though the same question asked
            // in four words finds it at once. Costs no model tokens (retrieval is
            // local) and the merge is capped at the same limit, so the prompt the
            // tutor receives is exactly as big as before -- the excerpts in it are
            // just better chosen.
            $focus = rag::focus_query($retrievalquery);
            if ($focus !== '') {
                $focused = rag::retrieve_diagnosed($courseid, $focus, $limit, $blockinstanceid);
                $chunks = rag::merge_chunks([$chunks, $focused['chunks']], $limit);
            }

            // Conditional query rewriting: only when the message itself is too
            // vague to retrieve on, or the first pass came back weak, spend one
            // cheap model call to expand the query and retry. Both passes are
            // merged: the rewritten query is a different question, so its top
            // score says nothing about whether its excerpts are better.
            $debug = (int)get_config('block_openaiagent', 'debugmode') === 1;
            if (
                query_rewriter::enabled()
                && (query_rewriter::is_vague($message)
                    || query_rewriter::is_weak_retrieval($diagnosed, $retrievalquery))
            ) {
                $rewritten = query_rewriter::rewrite($this->client, $message, $conversation);
                if (
                    $rewritten !== ''
                    && \core_text::strtolower($rewritten) !== \core_text::strtolower($retrievalquery)
                ) {
                    $second = rag::retrieve_diagnosed($courseid, $rewritten, $limit, $blockinstanceid);
                    $chunks = rag::merge_chunks([$chunks, $second['chunks']], $limit);
                    if ($debug) {
                        debugging('block_openaiagent query rewrite: original="' . $retrievalquery
                            . '"; rewritten="' . $rewritten
                            . '"; scores=' . round($diagnosed['topscore'], 4)
                            . '->' . round($second['topscore'], 4)
                            . '; merged to ' . count($chunks) . ' excerpts', DEBUG_DEVELOPER);
                    }
                } else if ($debug) {
                    debugging('block_openaiagent query rewrite: triggered but produced no usable query for "'
                        . $retrievalquery . '"', DEBUG_DEVELOPER);
                }
            }

            $ragcontext = rag::format_context($chunks);
            if ($ragcontext !== '') {
                $instructions .= "\n\n" . $ragcontext;
            } else {
                // No documents matched (or none are indexed yet): say so explicitly
                // so the tutor applies the configured no-information fallback
                // instead of inventing content.
                $instructions .= "\n\nNo course document excerpts are available for this question. "
                    . 'Apply the no-information fallback unless the question is a permitted '
                    . 'clarification of your previous answer.';
            }
        }

        // Restate the language AFTER the excerpts. The excerpts are in the course's
        // language and they are the last thing the model reads on this route, so a
        // directive left above them loses the recency position it was placed for.
        $languageline = self::explicit_language_directive($message, $config);
        if ($languageline !== '') {
            $instructions .= "\n\n" . $languageline;
        }

        return $this->execute_agent($agent, $instructions, $message, [], $config, $conversation);
    }

    /**
     * Build the knowledge-base retrieval query for a tutor turn.
     *
     * A short, contentless follow-up ("yes, find it", "and the page?") carries
     * almost no signal on its own, so retrieval would miss the section the user
     * is still asking about. When the current message is short, anchor it with
     * the most recent substantive user turn so the follow-up inherits the topic.
     * Messages that already carry enough content are used verbatim so a genuine
     * topic switch is not dragged back to the previous subject.
     *
     * @param string $message Current user message.
     * @param \stdClass $conversation Conversation record.
     * @return string Query text for {@see rag::retrieve}.
     */
    private static function build_retrieval_query(string $message, \stdClass $conversation): string {
        $message = trim($message);
        $words = preg_split('/\s+/u', $message) ?: [];
        if (count($words) >= 6) {
            return $message;
        }

        // The recent history is chronological {role, content}; the last user
        // turn is the current message (persisted before routing), so scan
        // backwards for an earlier, substantive user turn to anchor on.
        $history = conversation_repository::recent_history((int)$conversation->id, 8);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] !== 'user') {
                continue;
            }
            $prev = trim((string)$history[$i]['content']);
            if ($prev === '' || $prev === $message) {
                continue;
            }
            if (count(preg_split('/\s+/u', $prev) ?: []) >= 3) {
                return $prev . "\n" . $message;
            }
        }
        return $message;
    }

    /**
     * Run the platform assistant (grounded in live Moodle data via local tools).
     *
     * @param array $config Effective config.
     * @param string $message User message.
     * @param \stdClass $conversation Conversation record.
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @return array Partial result.
     */
    private function run_assistant(
        array $config,
        string $message,
        \stdClass $conversation,
        int $userid,
        int $courseid
    ): array {
        $agent = $config['agents']['assistant'];
        if ($agent === null) {
            return self::error_result('error_openai_failed', (int)$conversation->id);
        }

        // Support escalation lives here and only here. The tutor and ambiguity
        // routes pass no tools at all, so this is the single place where the
        // feature can reach a model, and the gate decides per turn whether the
        // drafting tool is part of the schema. When it is not, the model has no
        // way to offer an escalation: it does not know the tool exists.
        $toolnames = $config['tools'];
        $escalation = false;
        $trigger = '';
        $support = $config['support'] ?? [];

        if (!empty($support['enabled'])) {
            // Read-only and free of side effects, so it can stay available: it
            // is what lets the assistant answer "was my query sent?" from the
            // record instead of from memory.
            $toolnames[] = 'moodle.support_request_status';

            $gate = support_gate::evaluate($config, $conversation, $message, $userid, $courseid);
            if ($gate['allowed']) {
                $toolnames[] = 'moodle.support_request_draft';
                $escalation = true;
                $trigger = (string)$gate['trigger'];
            }
            if ((int)get_config('block_openaiagent', 'debugmode') === 1) {
                debugging(
                    'block_openaiagent support gate: ' . ($gate['allowed'] ? 'offered' : 'withheld')
                        . ' (reason=' . $gate['reason'] . ', trigger=' . $gate['trigger'] . ')',
                    DEBUG_DEVELOPER
                );
            }
        }

        $tools = self::function_tools($toolnames);

        // A per-course assistant prompt overrides the default agent prompt.
        $baseprompt = $config['assistantprompt'] !== '' ? $config['assistantprompt'] : $agent->baseprompt;
        $instructions = $this->compose_instructions(
            $baseprompt,
            $config,
            'assistant',
            $conversation,
            $message,
            $toolnames,
            $escalation
        );

        $result = $this->execute_agent(
            $agent,
            $instructions,
            $message,
            $tools,
            $config,
            $conversation,
            $userid,
            $courseid,
            $toolnames
        );

        // Whether the reply just written points the participant at support. Used
        // only to reconcile the wording with a card that the OPEN gate produced.
        //
        // It deliberately no longer opens the gate by itself. Inferring "this
        // person is stuck" from the prose was done with a keyword regex, and the
        // regex cannot tell a dead end from a precaution: an answer that resolved
        // the question and closed with "if the field will not save, contact
        // support" got a card bolted underneath it. Measured on a course run,
        // three of every four cards were produced this way, none of them needed.
        // The signal is not lost, only delayed by one turn: if the participant
        // replies at all after being sent to support, TRIGGER_RECOMMENDED opens
        // the gate on the next turn, which is also when their insistence has
        // actually confirmed they are stuck.
        $recommended = !empty($support['enabled'])
            && support_gate::recommends_support((string)($result['reply'] ?? ''));

        if ($escalation) {
            $prepared = $this->ensure_support_offer(
                $config,
                $conversation,
                $message,
                $userid,
                $courseid,
                $trigger,
                $recommended
            );

            // The model pointed at the support form and the server put a card
            // underneath it. Left alone the two contradict each other: the text
            // sends the participant away while the card offers to do it here.
            // One sentence, written by us, reconciles them -- and it goes into
            // the stored message as well, so a reload shows the same thing.
            if ($prepared && $recommended) {
                // In the language the participant is being answered in, not the
                // site's. get_string() would resolve against the site default and
                // staple a Spanish sentence onto an English reply, which is what
                // it did until this was measured on a run with EN and PT turns.
                $invitation = get_string_manager()->get_string(
                    'support_offer_inline',
                    'block_openaiagent',
                    null,
                    self::reply_language((string)($result['reply'] ?? ''))
                );
                $result['reply'] = trim((string)$result['reply']) . "\n\n" . $invitation;
                conversation_repository::append_to_last_assistant((int)$conversation->id, $invitation);
            }
        }

        return $result;
    }

    /**
     * The language a reply was written in, for the strings we staple onto it.
     *
     * Only the three languages the rest of the plugin's detectors handle, and
     * only from words that actually separate them: "para", "con" and "curso" are
     * shared by Spanish and Portuguese and would decide nothing. A clear winner
     * is required, so a reply we cannot place keeps whatever language the caller
     * would have used anyway.
     *
     * @param string $reply The reply just written.
     * @return string|null Language code, or null to let get_string decide.
     */
    private static function reply_language(string $reply): ?string {
        $text = ' ' . \core_text::strtolower(strip_tags($reply)) . ' ';
        $markers = [
            'es' => ['ñ', 'ción', ' actividad', ' usted ', ' tú ', ' tus ', ' qué ', ' está ', ' puedes ', ' desde '],
            'pt' => ['ç', 'ção', ' atividade', ' você ', ' não ', ' já ', ' está ', ' pode ', ' seu ', ' precisa '],
            'en' => [' the ', ' you ', ' your ', ' and ', ' with ', ' week ', ' activity', ' course ', ' is ', ' complete '],
        ];

        $scores = [];
        foreach ($markers as $lang => $words) {
            $score = 0;
            foreach ($words as $word) {
                $score += substr_count($text, $word);
            }
            $scores[$lang] = $score;
        }
        arsort($scores);
        $best = array_key_first($scores);
        $ordered = array_values($scores);

        // A win by one word is not a decision. Spanish and Portuguese share too
        // much for that, and guessing wrong is worse than not guessing.
        if ($ordered[0] < 2 || $ordered[0] < $ordered[1] * 2) {
            return null;
        }

        return $best;
    }

    /**
     * Make sure a participant who needs the offer actually gets it.
     *
     * The gate opened, so the model was given the drafting tool. If it chose not
     * to use it, the server prepares the draft itself. Without this, whether a
     * participant is offered help depends on the model's mood, which is exactly
     * the kind of thing the rest of this plugin refuses to leave to chance.
     *
     * Restricted to the cases where the need is unambiguous: the participant
     * asked for a person, the assistant has already fallen back, or the reply
     * just written tells them to contact support. On the weaker signals the
     * model's judgement is left to stand, so an unwanted card never appears on a
     * conversation that is merely repetitive.
     *
     * @param array $config Effective course config.
     * @param \stdClass $conversation Conversation record.
     * @param string $message Current user message.
     * @param int $userid Participant id.
     * @param int $courseid Course id.
     * @param string $trigger Trigger that opened the gate.
     * @param bool $recommended Whether the reply just written points at support.
     * @return bool True when a draft was prepared here.
     */
    private function ensure_support_offer(
        array $config,
        \stdClass $conversation,
        string $message,
        int $userid,
        int $courseid,
        string $trigger,
        bool $recommended = false
    ): bool {
        $backstopped = [
            support_gate::TRIGGER_ASKED,
            support_gate::TRIGGER_FALLBACK,
            support_gate::TRIGGER_RECOMMENDED,
            // An accepted offer is the least ambiguous signal of the lot, and the
            // one where leaving it to the model costs the most: it had the tool,
            // it was told yes, and it answered by making the same offer again.
            support_gate::TRIGGER_ACCEPTED,
        ];
        if (!$recommended && !in_array($trigger, $backstopped, true)) {
            return false;
        }

        // The model did its job: nothing to add.
        if (supportrequest::has_pending_draft((int)$conversation->id)) {
            return false;
        }

        // Re-checked because minutes of model latency sit between the gate and
        // this point, and another turn may have consumed the last allowance.
        $blocked = support_gate::hard_preconditions($config, (int)$conversation->id, $userid, $courseid);
        if ($blocked !== '') {
            return false;
        }

        $summary = $this->summarise_incident($config, $conversation, $message);

        // Same rule as the tool takes: an identical request already on its way
        // is not offered a second time. Here it simply means no card, because
        // the reply has already been written and rewriting it to explain would
        // be putting words in the assistant's mouth after the fact.
        $duplicate = supportrequest::find_duplicate(
            $courseid,
            $userid,
            supportrequest::summary_hash($summary),
            (int)($config['support']['dedupehours'] ?? 0)
        );
        if ($duplicate !== null) {
            return false;
        }

        supportrequest::create_draft(
            $courseid,
            (int)($conversation->blockinstanceid ?? 0),
            $userid,
            (int)$conversation->id,
            $summary,
            supportrequest::CATEGORY_FALLBACK
        );

        return true;
    }

    /**
     * Write the incident summary for a server-prepared draft.
     *
     * One small model call with a fixed instruction, no tools and no personal
     * data. If it fails or comes back empty the participant's own words are
     * used: a plainer summary is a far better outcome than no offer at all.
     *
     * @param array $config Effective course config.
     * @param \stdClass $conversation Conversation record.
     * @param string $message Current user message.
     * @return string
     */
    private function summarise_incident(array $config, \stdClass $conversation, string $message): string {
        $agent = $config['agents']['assistant'] ?? null;
        if ($agent !== null) {
            try {
                $request = new request();
                $request->model = $this->resolve_model($agent, $config, 'assistant');
                $request->instructions = defaults::SUPPORT_SUMMARY_PROMPT;
                $request->messages = conversation_repository::recent_history((int)$conversation->id, 6);
                $request->temperature = 0.2;
                $request->maxtokens = 300;
                $request->add_user_message($message);

                $response = $this->client->complete($request);
                if ($response->success && trim($response->text) !== '') {
                    // Billed like any other call, so it is recorded like any
                    // other call. An invisible cost is a cost nobody can manage.
                    conversation_repository::record_internal_usage(
                        (int)$conversation->id,
                        'support',
                        $request->model,
                        $response
                    );

                    return $response->text;
                }
            } catch (\Throwable $e) {
                debugging(
                    'block_openaiagent could not summarise a support incident: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $message;
    }

    /**
     * Run the ambiguity agent (asks a clarifying question).
     *
     * @param array $config Effective config.
     * @param string $message User message.
     * @param \stdClass $conversation Conversation record.
     * @return array Partial result.
     */
    private function run_ambiguity(array $config, string $message, \stdClass $conversation): array {
        $agent = $config['agents']['ambiguity'];
        if ($agent === null) {
            // Deterministic fallback so the user always gets a clarifying prompt.
            return [
                'success' => true,
                'reply' => get_string('ambiguity_fallback', 'block_openaiagent'),
                'errorcode' => '',
                'tokens' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
            ];
        }

        $instructions = $this->compose_instructions($agent->baseprompt, $config, 'ambiguity', $conversation, $message);
        return $this->execute_agent($agent, $instructions, $message, [], $config, $conversation);
    }

    /**
     * Run an agent turn, executing tool calls locally until the model answers.
     *
     * Conversation continuity comes from replaying recent local history (the
     * providers are stateless), so this works identically for every provider.
     *
     * @param \stdClass $agent Agent record.
     * @param string $instructions Composed instructions.
     * @param string $message Current user message.
     * @param array $tools Neutral tool definitions ([] = no tools).
     * @param array $config Effective config.
     * @param \stdClass $conversation Conversation record.
     * @param int $userid Authoritative user id for tool execution.
     * @param int $courseid Authoritative course id for tool execution.
     * @param array|null $allowedtoolnames Names executable this turn (null = the course list).
     * @return array Partial result.
     */
    private function execute_agent(
        \stdClass $agent,
        string $instructions,
        string $message,
        array $tools,
        array $config,
        \stdClass $conversation,
        int $userid = 0,
        int $courseid = 0,
        ?array $allowedtoolnames = null
    ): array {
        // Execution is validated against the same list the schema was built
        // from. Falling back to the course list would make a tool that was
        // appended for this turn unusable the moment the model called it.
        $allowed = $allowedtoolnames ?? $config['tools'];
        $toolfailures = [];
        $request = $this->build_request($agent, $instructions, $agent->agenttype, $config);
        $request->tools = $tools;

        // Recent history normally already includes the current user turn (it was
        // persisted before routing), but with message logging disabled the stored
        // content is empty, so make sure the model always receives the message.
        $maxmessages = (int)get_config('block_openaiagent', 'history_max_messages');
        if ($maxmessages <= 0) {
            $maxmessages = 6;
        }
        $request->messages = conversation_repository::recent_history((int)$conversation->id, $maxmessages);
        $last = end($request->messages);
        if ($last === false || $last['role'] !== 'user' || $last['content'] !== $message) {
            $request->add_user_message($message);
        }

        $prompttokens = 0;
        $cachedtokens = 0;
        $completiontokens = 0;
        $totaltokens = 0;
        $response = null;

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            $response = $this->client->complete($request);
            if (!$response->success) {
                break;
            }

            $prompttokens += $response->prompttokens;
            $cachedtokens += $response->cachedtokens;
            $completiontokens += $response->completiontokens;
            $totaltokens += $response->totaltokens;

            if (!$response->has_tool_calls() || empty($tools)) {
                break;
            }

            // Budget spent. Leave these pending calls to the forced final answer
            // below: it feeds them back and then asks for prose with the tools
            // removed. Feeding them here as well would send the same assistant
            // message twice, repeat every tool_call_id, and re-run every tool.
            if ($iteration === self::MAX_TOOL_ITERATIONS - 1) {
                break;
            }

            // Feed the tool calls and their locally-executed results back in.
            $request->add_assistant_message($response->text, $response->toolcalls);
            foreach ($response->toolcalls as $call) {
                $output = self::execute_tool_call($call, $allowed, $userid, $courseid, $conversation);
                self::note_tool_failure($call, $output, $toolfailures);
                $request->add_tool_result((string)$call['id'], (string)$call['name'], $output);
            }
        }

        // The loop can run out of budget with the model still asking for tools:
        // it has spent every round exploring and has written no prose at all, so
        // its text is empty and used to become "the assistant is temporarily
        // unavailable" -- a hard failure for the participant on exactly the
        // questions that need the most lookups. Ask once more with the tools
        // removed, so the only move left is to answer with what it has already
        // gathered. This call is also the cheapest of the turn: without the tool
        // schemas the prompt is a fraction of the size.
        $exhausted = false;
        if ($response !== null && $response->success && $response->has_tool_calls() && !empty($tools)) {
            $exhausted = true;
            // The provider requires a result for every pending call before the
            // conversation can continue, so run them: the model asked for this
            // data and it is what the final answer will be built from.
            $request->add_assistant_message($response->text, $response->toolcalls);
            foreach ($response->toolcalls as $call) {
                $output = self::execute_tool_call($call, $allowed, $userid, $courseid, $conversation);
                self::note_tool_failure($call, $output, $toolfailures);
                $request->add_tool_result((string)$call['id'], (string)$call['name'], $output);
            }
            $request->tools = [];
            $request->add_user_message(self::FINAL_ANSWER_INSTRUCTION);

            $final = $this->client->complete($request);
            if ($final->success) {
                $prompttokens += $final->prompttokens;
                $cachedtokens += $final->cachedtokens;
                $completiontokens += $final->completiontokens;
                $totaltokens += $final->totaltokens;
                $response = $final;
            }
            $outcome = $final->success && $final->text !== '' ? 'recovered' : 'still empty';
            debugging(
                'block_openaiagent tool budget exhausted after ' . self::MAX_TOOL_ITERATIONS
                    . ' rounds on route ' . $agent->agenttype
                    . '; forced a final answer without tools (' . $outcome . ')',
                DEBUG_DEVELOPER
            );
        }

        if ($response === null || !$response->success) {
            $failure = $response ?? response::failure('apierror');
            // Any iteration that completed before the failure was still billed
            // (a tool round-trip that succeeded, then an error on the next call),
            // so its tokens are recorded rather than silently dropped.
            conversation_repository::add_message($conversation->id, 'assistant', '', [
                'route' => $agent->agenttype,
                'agentid' => (int)$agent->id,
                'model' => $request->model,
                'prompttokens' => $prompttokens,
                'cachedtokens' => $cachedtokens,
                'completiontokens' => $completiontokens,
                'totaltokens' => $totaltokens,
                'errormessage' => self::sanitize_error($failure),
            ]);
            return self::error_result(self::map_error_code($failure->errorcode), (int)$conversation->id);
        }

        // An empty reply is a failure the participant sees, but the call itself
        // succeeded, so it used to be stored with no errormessage and counted as
        // a healthy turn: the dashboard reported zero errors while a third of the
        // assistant's answers were the generic unavailable message. Record why.
        $emptyreason = '';
        if ($response->text === '') {
            $emptyreason = $exhausted
                ? 'Empty reply: tool-call budget exhausted after ' . self::MAX_TOOL_ITERATIONS
                    . ' rounds and the forced final answer produced no text either.'
                : 'Empty reply from the model with no tool calls pending.';
        }

        $reply = $response->text !== '' ? $response->text : get_string('error_openai_failed', 'block_openaiagent');
        $reply = self::strip_internal_state($reply);

        // A failed tool is what turns "the assistant could not help" into a fact
        // the next turn can act on. Only the tool name and the error code are
        // kept, on the errormessage column, which survives the content blanking
        // that the logging settings apply: no arguments, no payload, nothing the
        // participant wrote.
        if (!empty($toolfailures)) {
            conversation_repository::add_message($conversation->id, 'tool', '', [
                'route' => $agent->agenttype,
                'agentid' => (int)$agent->id,
                'errormessage' => \core_text::substr(implode('; ', array_unique($toolfailures)), 0, 255),
            ]);
        }

        conversation_repository::add_message($conversation->id, 'assistant', $reply, [
            'route' => $agent->agenttype,
            'agentid' => (int)$agent->id,
            'model' => $request->model,
            'openairesponseid' => $response->id,
            'errormessage' => $emptyreason,
            'prompttokens' => $prompttokens,
            'cachedtokens' => $cachedtokens,
            'completiontokens' => $completiontokens,
            'totaltokens' => $totaltokens,
        ]);

        if ($response->id !== '') {
            conversation_repository::update($conversation, [
                'lastresponseid' => $response->id,
                'activeagentid' => (int)$agent->id,
            ]);
        }

        return [
            'success' => true,
            'reply' => $reply,
            'errorcode' => '',
            'tokens' => [
                'prompt' => $prompttokens,
                'completion' => $completiontokens,
                'total' => $totaltokens,
            ],
        ];
    }

    /**
     * Assemble a provider-neutral request shell for a route.
     *
     * @param \stdClass $agent Agent record.
     * @param string $instructions System instructions.
     * @param string $route Route key (router|tutor|assistant|ambiguity).
     * @param array $config Effective config.
     * @return request
     */
    private function build_request(\stdClass $agent, string $instructions, string $route, array $config): request {
        $request = new request();
        $request->model = $this->resolve_model($agent, $config, $route);
        $request->instructions = $instructions;
        $request->temperature = self::resolve_temperature($agent, $config, $route);
        $request->maxtokens = self::resolve_max_tokens($agent, $config, $route);
        // Neutral effort level; each adapter maps it to its provider's mechanism
        // and ignores it on models that do not reason. Empty means "send nothing",
        // so an install that never touches this setting keeps its old behaviour.
        $request->reasoningeffort = trim((string)get_config('block_openaiagent', 'reasoning_effort'));
        return $request;
    }

    /**
     * Build neutral function-tool definitions for the enabled Moodle tools.
     *
     * Session/identity parameters are stripped from the schemas: the server
     * injects the authenticated user id and course id at execution time, so the
     * model can neither see nor spoof them. Dots in tool names are encoded as
     * double underscores because most providers restrict function names to
     * [a-zA-Z0-9_-].
     *
     * @param string[] $allowednames Enabled tool names for the course.
     * @return array[] Neutral tool definitions.
     */
    public static function function_tools(array $allowednames): array {
        $allowed = array_fill_keys($allowednames, true);
        $tools = [];

        foreach (tool_registry::list_tools() as $tool) {
            $name = (string)$tool['name'];
            if (!isset($allowed[$name])) {
                continue;
            }

            $schema = $tool['input_schema'];
            foreach (['mcp_session_token', 'user_id', 'course_id'] as $serverparam) {
                unset($schema['properties'][$serverparam]);
            }
            if (isset($schema['required'])) {
                $schema['required'] = array_values(array_diff(
                    $schema['required'],
                    ['mcp_session_token', 'user_id', 'course_id']
                ));
            }
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }

            $tools[] = [
                'name' => str_replace('.', '__', $name),
                'description' => (string)$tool['description'],
                'parameters' => $schema,
            ];
        }

        return $tools;
    }

    /**
     * Record a tool call that came back as an error.
     *
     * @param array $call The tool call as the model made it.
     * @param string $output Raw JSON returned by the executor.
     * @param string[] $failures Accumulator, modified in place.
     * @return void
     */
    private static function note_tool_failure(array $call, string $output, array &$failures): void {
        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['error'])) {
            return;
        }

        $name = str_replace('__', '.', (string)($call['name'] ?? 'unknown'));
        $failures[] = $name . ':' . (string)$decoded['error'];
    }

    /**
     * Execute one model tool call against the local tool registry.
     *
     * @param array $call Tool call ['id', 'name', 'arguments'].
     * @param string[] $allowednames Enabled tool names for the course.
     * @param int $userid Authoritative user id.
     * @param int $courseid Authoritative course id.
     * @param \stdClass|null $conversation Conversation the call belongs to, when there is one.
     * @return string JSON-encoded tool output (or error object).
     */
    private static function execute_tool_call(
        array $call,
        array $allowednames,
        int $userid,
        int $courseid,
        ?\stdClass $conversation = null
    ): string {
        $toolname = str_replace('__', '.', (string)($call['name'] ?? ''));
        $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        $debug = (int)get_config('block_openaiagent', 'debugmode') === 1;

        if (!in_array($toolname, $allowednames, true) || $userid <= 0 || $courseid <= 0) {
            if ($debug) {
                debugging('block_openaiagent tool call: name=' . $toolname . '; result=tool_not_available'
                    . ' (enabled tools: ' . implode(', ', $allowednames) . ')', DEBUG_DEVELOPER);
            }
            return json_encode(['error' => 'tool_not_available']);
        }

        // The Moodle session is authoritative: whatever the model put in the
        // arguments is overwritten, mirroring the MCP endpoint behaviour.
        $arguments['user_id'] = $userid;
        $arguments['course_id'] = $courseid;
        unset($arguments['mcp_session_token']);

        try {
            $result = tool_registry::call($toolname, $arguments, $userid, $courseid, [
                'conversationid' => (int)($conversation->id ?? 0),
                'blockinstanceid' => (int)($conversation->blockinstanceid ?? 0),
            ]);
        } catch (\Throwable $e) {
            if ($debug) {
                debugging('block_openaiagent tool call: name=' . $toolname
                    . '; args=' . json_encode($arguments, JSON_UNESCAPED_UNICODE)
                    . '; EXCEPTION ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return json_encode(['error' => 'tool_failed']);
        }

        // UNESCAPED_SLASHES matters as much as UNESCAPED_UNICODE here: without it
        // every "/" is sent as "\/", and models copy that literally into the reply
        // ("Consultas al tutor\/a", "http:\/\/..."). client_base and embeddings
        // already encode with both flags; this call site was missing one.
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return json_encode(['error' => 'tool_failed']);
        }
        if (\core_text::strlen($encoded) > self::MAX_TOOL_RESULT_CHARS) {
            $encoded = \core_text::substr($encoded, 0, self::MAX_TOOL_RESULT_CHARS);
        }

        if ($debug) {
            debugging('block_openaiagent tool call: name=' . $toolname
                . '; args=' . json_encode($arguments, JSON_UNESCAPED_UNICODE)
                . '; result=' . $encoded, DEBUG_DEVELOPER);
        }

        return $encoded;
    }

    /**
     * Compose the final instruction string, never returning null fragments.
     *
     * @param string $baseprompt Agent base prompt.
     * @param array $config Effective config.
     * @param string $route Route key.
     * @param \stdClass $conversation Conversation record.
     * @param string $message Current user message (for language detection; '' to skip).
     * @param array|null $toolnames Effective tool names for this turn (null = the course list).
     * @param bool $escalation Whether the support drafting tool is exposed this turn.
     * @return string
     */
    private function compose_instructions(
        string $baseprompt,
        array $config,
        string $route,
        \stdClass $conversation,
        string $message = '',
        ?array $toolnames = null,
        bool $escalation = false
    ): string {
        // Everything from here to the identity block below is identical for every
        // participant in the course, and that is deliberate: providers cache by
        // PREFIX, so the shared part has to come first and the per-user part
        // last. The user's name used to be injected here, in second position,
        // which capped the shared prefix at the base prompt alone (~1.000 tokens,
        // right at the 1.024-token minimum a provider needs before it caches
        // anything). Every participant paid full price for the same course
        // prompt, documents and policies, and the cache only ever helped within
        // one person's own burst of questions.
        $parts = [trim($baseprompt)];

        // Tutor only. This field is the per-course TUTOR prompt (that is what the
        // form labels it), so courses legitimately write a full tutor persona in
        // it -- "You are the Official Academic Tutor ... not a technical assistant
        // for the classroom". Injecting that into the assistant put two
        // contradictory identities in the same system message, and the assistant
        // answered as the tutor (refusing platform help, declining to put a user in
        // touch with their real tutor). The ambiguity agent got it too and started
        // citing invented document sections it has no access to. It also cost a
        // course-sized prompt on every iteration of every turn on all three routes.
        // The assistant has its own per-course prompt (assistantprompt).
        if ($route === 'tutor') {
            if ($config['citabledocuments'] !== '') {
                $parts[] = "Citable documents (you may reference these):\n" . trim($config['citabledocuments']);
            }
            if ($config['internaldocuments'] !== '') {
                $parts[] = "Internal documents (never cite or quote these):\n" . trim($config['internaldocuments']);
            }
            if ($config['protectedactivities'] !== '') {
                $parts[] = "Protected/evaluative activities:\n" . trim($config['protectedactivities']);
            }
            if ($config['evaluationpolicy'] !== '') {
                $parts[] = "Evaluation policy:\n" . trim($config['evaluationpolicy']);
            }
            // Both fallbacks describe a WHOLE reply, not a closing paragraph. Said
            // only as "convey this message", the model treated them as a coda and
            // pasted them onto answers it had already given in full -- a correct,
            // well-cited reply that then tells the participant the material does
            // not cover what was just explained. Saying plainly that the message
            // replaces the answer, and forbidding the append, is the whole fix.
            if ($config['fallbacknoinfo'] !== '') {
                $parts[] = "No-information fallback. Use it ONLY when your entire reply is a "
                    . "negative -- you have nothing from the documents to offer. Convey its "
                    . "MEANING in the user's language; it is not a fixed string to reproduce "
                    . "verbatim. NEVER append it to a reply that already answered the question "
                    . "or any part of it: if you answered partly, say in one sentence of your "
                    . "own which specific part the documents do not cover, and do not use this "
                    . "message at all.\n" . trim($config['fallbacknoinfo']);
            }
            if ($config['fallbackoutofscope'] !== '') {
                $parts[] = "Out-of-scope fallback. Use it ONLY when the request is out of scope "
                    . "and it is the whole of your reply. Convey its MEANING in the user's "
                    . "language; it is not a fixed string to reproduce verbatim. NEVER append it "
                    . "to a reply that already answered the question, and never repeat it twice "
                    . "in one reply.\n" . trim($config['fallbackoutofscope']);
            }
            if ($config['fallbackevaluationblock'] !== '') {
                $parts[] = "Evaluation-block message (for graded assessment items, convey this "
                    . "message in the user's language):\n" . trim($config['fallbackevaluationblock']);
            }
        }

        if ($route === 'assistant') {
            if ($config['assistantfaqs'] !== '') {
                $parts[] = "Course FAQs (use these to answer when relevant):\n" . trim($config['assistantfaqs']);
            }
            // The effective list, not the course list: the support tools are
            // appended per turn and the model has to be told about the tools it
            // has actually been given.
            $effectivetools = $toolnames ?? $config['tools'];
            if (!empty($effectivetools)) {
                $parts[] = "You may call these Moodle tools: " . implode(', ', $effectivetools) . '.';
            }
            // Gated on the tool actually being enabled for this course, so the
            // course tool checkbox is a single switch for the whole feature:
            // a course with it unchecked neither gets the rule nor pays for its
            // tokens, and turning it off is a complete rollback with no deploy.
            if (in_array('moodle.get_activity_configuration', $config['tools'], true)) {
                $parts[] = defaults::ASSISTANT_ACTIVITY_CONFIG_DIRECTIVE;
            }
            if (in_array('moodle.support_request_status', $effectivetools, true)) {
                $parts[] = defaults::SUPPORT_STATUS_DIRECTIVE;
            }
            // Only on the turns where the gate actually opened. A conversation
            // that is going fine never reads these rules and never pays for
            // their tokens.
            if ($escalation) {
                $parts[] = defaults::SUPPORT_ESCALATION_DIRECTIVE;
                $sla = trim((string)($config['support']['slatext'] ?? ''));
                if ($sla !== '') {
                    $parts[] = 'If a request is prepared, tell the participant the expected response time '
                        . 'exactly as written here, in their language: ' . $sla;
                }
            }
        }

        if ($route !== 'router') {
            $parts[] = self::language_directive($config['languagepolicy']);
            // Replies are rendered as Markdown in the chat UI, so nudge the model
            // towards the constructs the bubble styles support.
            $parts[] = 'Format replies with simple Markdown when it improves readability: '
                . 'short paragraphs, **bold** for key terms, "-" bullet lists, and [text](url) '
                . 'links when you reference a URL. Do not use headings or tables.';
            // Identifier hygiene. Tool results carry internal numeric ids (course id,
            // section number, cmid/activity id, grade-item id) that the model needs
            // ONLY to chain further tool calls; they are meaningless to a student.
            // This directive is injected server-side on every non-router turn so it
            // cannot be weakened by a per-course prompt, which is the usual reason
            // such a rule "does not stick".
            $parts[] = 'Identifiers: NEVER reveal internal numeric identifiers to the user '
                . '(course id, section number, activity/module id or "cmid", grade-item id, '
                . 'or a bare code such as "37670"). Refer to every course, section and activity '
                . 'by its human-readable title exactly as given in the tool results (fields like '
                . 'course_fullname, section_name or name). If a tool gives you only an id and no '
                . 'title for something, describe it generically (e.g. "a previous activity") and '
                . 'never state the number. Never tell the user to search for or open something "by '
                . 'its id". A numeric id may appear ONLY inside a clickable link URL, never as a '
                . 'standalone identifier in your prose.';
            // Same server-side reasoning as the identifier rule above: these are
            // output-hygiene failures a per-course prompt keeps not catching.
            // Raw field names leak the shape of the tool result ("las actividades
            // figuran sin calificacion (user_grade: null)"), and a reflexive "Si,"
            // opening in front of a negative answer inverts the meaning for a
            // participant who reads only the first three words.
            $parts[] = 'Tool results are raw data, not text to quote. NEVER show the participant a '
                . 'field name, a JSON key, a raw null/true/false, or a fragment of the tool payload '
                . '(for example "user_grade: null"). State what it means in plain prose: "no grade '
                . 'has been recorded yet".';
            $parts[] = 'Never open with "Yes" or "No" unless it matches the polarity of what follows. '
                . 'For a question like "have I already done X?" whose true answer is negative, open '
                . 'with the negative ("No, you have not completed it yet"), never with an affirmative '
                . 'followed by a denial.';
        }

        // END of the course-wide, cacheable prefix. Everything below varies per
        // user or per conversation, so it goes last on purpose: anything placed
        // above this line is shared by every participant and billed at the
        // cached rate after the first turn of the day.
        //
        // The first name and the course title come from Moodle, not from a
        // prompt, so the agents can greet the participant and name the course
        // without spending a tool call.
        if ($route !== 'router') {
            $identity = self::identity_directive($conversation);
            if ($identity !== '') {
                $parts[] = $identity;
            }
        }

        // Language, decided in PHP and restated LAST. The generic "reply in the
        // user's language" rule sits ~2.000 tokens up in the cacheable prefix and
        // competes with everything between: an English course prompt, Spanish
        // fallback messages and Spanish document excerpts. Measured on the 144-item
        // battery, 2 of 6 English questions still came back in Spanish. Naming the
        // detected language explicitly, adjacent to the user turn, replaces a rule
        // the model has to apply with a fact it only has to obey. It sits after the
        // identity block, so it costs a few uncached tokens per turn and nothing in
        // cache efficiency -- the shared prefix above is unchanged.
        if ($route !== 'router') {
            $parts[] = self::explicit_language_directive($message, $config);
        }

        // Provide the running summary as extra context for longer conversations.
        if ($route !== 'router' && trim((string)$conversation->conversationsummary) !== '') {
            $parts[] = "Conversation summary so far:\n" . trim((string)$conversation->conversationsummary);
        }

        return implode("\n\n", array_filter($parts, static fn($p) => trim((string)$p) !== ''));
    }

    /**
     * Build a localized language directive line.
     *
     * @param string $policy auto or a language code.
     * @return string
     */
    private static function language_directive(string $policy): string {
        $base = ($policy === '' || $policy === 'auto')
            ? 'Write your ENTIRE reply in the same language as the user\'s latest message.'
            : 'Write your ENTIRE reply in this language: ' . $policy . '.';
        return $base . ' This overrides the language of everything else you were given:'
            . ' any fixed, fallback or template message, any course-specific instruction,'
            . ' and any text returned by tools or quoted from the course documents must be'
            . ' rendered in that language — translate it, never copy it in another language.';
    }

    /**
     * The explicit, detected-language line, or '' when detection is not used.
     *
     * Kept separate from {@see compose_instructions} because the tutor route has
     * to emit it a SECOND time. Measured: with the line placed last inside the
     * composed instructions, an English question on the assistant route came back
     * in English, but the same question on the tutor route still came back in
     * Spanish -- because run_tutor() appends the RAG context AFTER the composed
     * instructions, so thousands of tokens of Spanish excerpts take the recency
     * position the line was put there to occupy. Repeating one sentence after the
     * excerpts is cheap and puts it back where it has to be.
     *
     * @param string $message Current user message.
     * @param array $config Effective config.
     * @return string Directive line, or '' (no detection, or a fixed policy applies).
     */
    private static function explicit_language_directive(string $message, array $config): string {
        $policy = (string)($config['languagepolicy'] ?? '');
        if ($policy !== '' && $policy !== 'auto') {
            // A course that pins a language does not want it overridden per turn.
            return '';
        }
        $detected = self::detect_language($message);
        if ($detected === '') {
            return '';
        }
        return 'The participant wrote their latest message in ' . $detected
            . '. Write your ENTIRE reply in ' . $detected . ', including any fallback or template '
            . 'message and anything you take from the course documents or tool results: translate '
            . 'it, never copy it in the language of the source.';
    }

    /**
     * Detect the language of a user message, for the explicit language directive.
     *
     * Stopword frequency over the handful of languages a course of this kind
     * actually receives. Deliberately conservative: it returns '' unless one
     * language clearly wins, because a wrong confident label is worse than no
     * label at all -- with '' the generic "same language as the user" rule in
     * {@see language_directive} still applies, which is the current behaviour.
     *
     * Cheap by design (no model call, no library): the whole point is to spend
     * PHP instead of tokens on something a regex decides as well as a model.
     *
     * @param string $message User message.
     * @return string English name of the detected language, or '' when unsure.
     */
    private static function detect_language(string $message): string {
        $text = \core_text::strtolower(trim($message));
        if ($text === '' || \core_text::strlen($text) < 8) {
            // Too short to judge: "ok", "gracias", "thanks" would be guesses.
            return '';
        }

        // Function words only, and deliberately including the accented
        // interrogatives: "que/qual/como/onde" are shared with Portuguese, but
        // "que/cual/como/donde" WITH the accent are Spanish and nothing else, so
        // they are the cheapest way to break the Spanish/Portuguese tie that a
        // short question would otherwise leave open.
        $markers = [
            'Spanish' => ['el', 'la', 'los', 'las', 'de', 'del', 'que', 'qué', 'para', 'con',
                'una', 'un', 'por', 'como', 'cómo', 'sobre', 'esta', 'este', 'esa', 'ese',
                'donde', 'dónde', 'cual', 'cuál', 'cuáles', 'cuando', 'cuándo', 'pero',
                'porque', 'mi', 'mis', 'me', 'se', 'es', 'son', 'está', 'están', 'al', 'y',
                'no', 'si', 'sí', 'en', 'sus', 'su', 'más', 'muy', 'también', 'según',
                'lo', 'le', 'les', 'nos', 'te', 'ya', 'hay', 'ha', 'han', 'todo', 'bien',
                'puedo', 'puede', 'tengo', 'quiero', 'hacer', 'curso', 'gracias'],
            'English' => ['the', 'of', 'and', 'to', 'in', 'is', 'are', 'was', 'were', 'you',
                'your', 'for', 'with', 'this', 'that', 'can', 'could', 'how', 'what', 'when',
                'where', 'why', 'who', 'which', 'from', 'on', 'it', 'as', 'be', 'at', 'by',
                'not', 'there', 'we', 'they', 'their', 'our', 'do', 'does', 'my', 'me', 'i',
                'next', 'will', 'would', 'should', 'have', 'has', 'need', 'want', 'about',
                'any', 'all', 'if', 'so', 'but', 'or', 'an', 'a', 'please', 'course'],
            'Portuguese' => ['não', 'nao', 'para', 'com', 'uma', 'um', 'que', 'como', 'sobre',
                'você', 'voce', 'seu', 'sua', 'isso', 'isto', 'este', 'esta', 'onde', 'quando',
                'qual', 'mas', 'porque', 'são', 'sao', 'está', 'dos', 'das', 'deste', 'desta',
                'no', 'na', 'em', 'muito', 'mais', 'também', 'aqui', 'posso', 'obrigado',
                'curso'],
            'French' => ['le', 'la', 'les', 'des', 'du', 'une', 'un', 'que', 'qui', 'pour',
                'avec', 'dans', 'est', 'sont', 'vous', 'votre', 'cette', 'ce', 'comment',
                'quoi', 'quel', 'où', 'quand', 'pourquoi', 'mais', 'parce', 'sur', 'pas',
                'je', 'nous', 'mon', 'ma', 'mes', 'plus', 'très', 'au', 'cours', 'merci'],
        ];

        $words = preg_split('/[^\p{L}]+/u', $text) ?: [];
        if (count($words) < 3) {
            return '';
        }
        $counts = [];
        foreach ($markers as $language => $list) {
            $set = array_flip($list);
            $hits = 0;
            foreach ($words as $word) {
                if ($word !== '' && isset($set[$word])) {
                    $hits++;
                }
            }
            $counts[$language] = $hits;
        }

        // Orthography breaks the Spanish/Portuguese/French ties that stopwords
        // alone leave open, and inverted punctuation is Spanish and nothing else.
        if (preg_match('/[¿¡]|ñ/u', $text)) {
            $counts['Spanish'] += 3;
        }
        if (preg_match('/[ãõ]/u', $text)) {
            $counts['Portuguese'] += 3;
        }
        if (preg_match('/[àèùœ]/u', $text)) {
            $counts['French'] += 2;
        }

        arsort($counts);
        $ordered = array_keys($counts);
        $best = $ordered[0];
        $second = $counts[$ordered[1]] ?? 0;

        // Require a real signal and a clear LEAD over the runner-up, not a
        // ratio. A ratio rejects exactly the cases worth deciding: Spanish 3 vs
        // Portuguese 2 is a comfortable win on a short question, yet "best twice
        // the runner-up" throws it away and falls back to the generic rule --
        // which is the behaviour being replaced. A fixed lead of 2 keeps real
        // ties ("no gracias thanks") undecided, which is what '' is for.
        if ($counts[$best] < 2 || ($counts[$best] - $second) < 2) {
            return '';
        }
        return $best;
    }

    /**
     * Build an authoritative identity line from the conversation owner and course.
     *
     * The values come from the Moodle server context (the conversation record),
     * never from the user's message, so they cannot be spoofed. Returns an empty
     * string if neither value can be resolved.
     *
     * @param \stdClass $conversation Conversation record (carries userid, courseid).
     * @return string
     */
    private static function identity_directive(\stdClass $conversation): string {
        global $DB;

        $bits = [];

        // FIRST NAME ONLY. Everything sent here leaves the site and reaches a
        // third-party model, so the rule is data minimisation: the agents need a
        // way to address the participant, and a first name is enough for that.
        // The surname adds nothing to the answer and, combined with the course,
        // makes the person identifiable in someone else's logs. Never add the
        // email, the username, the ID number or any other profile field.
        $userid = (int)($conversation->userid ?? 0);
        if ($userid > 0) {
            $user = \core_user::get_user($userid, 'firstname', IGNORE_MISSING);
            if ($user) {
                $firstname = trim((string)$user->firstname);
                if ($firstname !== '') {
                    $bits[] = "address the participant as \"{$firstname}\" (first name only; "
                        . 'you have not been given their surname and must never guess or ask for it)';
                }
            }
        }

        $courseid = (int)($conversation->courseid ?? 0);
        if ($courseid > 0) {
            $fullname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
            if ($fullname !== false && trim((string)$fullname) !== '') {
                $bits[] = "the current course is \"" . trim((string)$fullname) . "\"";
            }
        }

        if (empty($bits)) {
            return '';
        }

        return 'Context (authoritative, provided by Moodle): ' . implode('; ', $bits)
            . '. Use these directly when addressing the user or referring to the course; '
            . 'do not call a tool to look them up.';
    }

    /**
     * Resolve the model for a route, honouring course override for content agents.
     *
     * The resolved id is validated against the active provider so that a model
     * left over from another provider (e.g. gpt-* while Anthropic is active)
     * falls back to the provider default instead of failing every call.
     *
     * @param \stdClass $agent Agent record.
     * @param array $config Effective config.
     * @param string $route Route key.
     * @return string
     */
    private function resolve_model(\stdClass $agent, array $config, string $route): string {
        // Precedence: course override > global admin setting > seeded agent
        // default. The global setting must beat the agent record: agents have
        // no editing UI, so their seeded defaultmodel would otherwise silently
        // pin the model and make the admin settings page a no-op.
        $configured = '';
        if ($route !== 'router' && $config['modeloverride'] !== '') {
            $configured = $config['modeloverride'];
        }
        if ($configured === '') {
            $configured = trim((string)get_config('block_openaiagent', 'default_' . $route . '_model'));
        }
        if ($configured === '') {
            $configured = trim((string)$agent->defaultmodel);
        }
        return factory::resolve_model($configured, $this->client);
    }

    /**
     * Resolve the temperature for a route.
     *
     * @param \stdClass $agent Agent record.
     * @param array $config Effective config.
     * @param string $route Route key.
     * @return float
     */
    private static function resolve_temperature(\stdClass $agent, array $config, string $route): float {
        // Same precedence as resolve_model() and resolve_max_tokens(): course
        // override > global admin setting > seeded agent default. The read of
        // the global setting was missing here, exactly as it had been for
        // max_output_tokens: router_temperature, tutor_temperature and
        // assistant_temperature rendered on the settings page and were never
        // applied, because the seeded agent record always won.
        if ($route !== 'router' && $config['temperatureoverride'] !== null) {
            return (float)$config['temperatureoverride'];
        }
        // An admin setting that has never been saved reads as false, and casting
        // that to float would silently pin the temperature at 0. Only a value
        // that is actually present may override the agent default; the ambiguity
        // route, which has no such setting, falls through here by design.
        $configured = get_config('block_openaiagent', $route . '_temperature');
        if ($configured !== false && trim((string)$configured) !== '') {
            return (float)$configured;
        }
        return (float)$agent->temperature;
    }

    /**
     * Resolve the max output tokens for a route.
     *
     * @param \stdClass $agent Agent record.
     * @param array $config Effective config.
     * @param string $route Route key.
     * @return int
     */
    private static function resolve_max_tokens(\stdClass $agent, array $config, string $route): int {
        // Same precedence as resolve_model(): course override > global admin
        // setting > seeded agent default. This read of the global setting was
        // missing, so max_output_tokens_router/tutor/assistant rendered on the
        // settings page but were never applied: the seeded agent record always
        // won and the three fields silently did nothing. That matters most on
        // reasoning models, where the cap also has to cover the reasoning tokens,
        // and a cap left at its seeded value makes the model spend the whole
        // budget thinking and return an empty answer.
        if ($route !== 'router' && $config['maxoutputtokensoverride'] !== null) {
            return (int)$config['maxoutputtokensoverride'];
        }
        $configured = (int)get_config('block_openaiagent', 'max_output_tokens_' . $route);
        if ($configured > 0) {
            return $configured;
        }
        $tokens = (int)$agent->maxoutputtokens;
        return $tokens > 0 ? $tokens : 1000;
    }

    /**
     * Map a provider client error code to a user-facing lang string key.
     *
     * @param string $errorcode Client error code.
     * @return string Lang string key (without component).
     */
    private static function map_error_code(string $errorcode): string {
        switch ($errorcode) {
            case 'ratelimited':
                return 'error_ratelimited';
            case 'noapikey':
            case 'invalidapikey':
            default:
                return 'error_openai_failed';
        }
    }

    /**
     * Remove leaked internal-state artifacts from a model reply.
     *
     * Some agent prompts historically asked the model to "update the conversation
     * summary", which the model occasionally emitted verbatim into the visible
     * answer ("state.conversation_summary = ...", a trailing "Resumen: ..." line).
     * The plugin never consumes that output, so strip it before the reply is
     * stored or shown. Defensive: any preg failure leaves the reply untouched.
     *
     * @param string $reply Raw model reply.
     * @return string Cleaned reply.
     */
    private static function strip_internal_state(string $reply): string {
        if ($reply === '') {
            return '';
        }
        // Lines that expose an internal state object.
        $reply = preg_replace('/^\s*state\.[A-Za-z_][\w.]*\s*=.*$/mu', '', $reply) ?? $reply;
        // A trailing standalone meta-summary line the model sometimes appends.
        $reply = preg_replace('/\n\s*Resumen\s*:\s*[^\n]*\s*$/iu', '', $reply) ?? $reply;
        // Tidy up blank lines the removals may leave behind.
        $reply = preg_replace('/\n{3,}/u', "\n\n", $reply) ?? $reply;
        return trim($reply);
    }

    /**
     * Produce a stored error message, redacting detail unless debug is on.
     *
     * @param response $response Failed response.
     * @return string
     */
    private static function sanitize_error(response $response): string {
        if ((int)get_config('block_openaiagent', 'debugmode') === 1) {
            return $response->errorcode . ': ' . $response->errormessage;
        }
        return $response->errorcode;
    }

    /**
     * Build a localized error result.
     *
     * @param string $errorcode Lang string key (without component).
     * @param int|null $conversationid Conversation id if known.
     * @return array
     */
    private static function error_result(string $errorcode, ?int $conversationid): array {
        $key = $errorcode !== '' ? $errorcode : 'error_openai_failed';
        return [
            'success' => false,
            'actions' => [],
            'reply' => get_string($key, 'block_openaiagent'),
            'route' => '',
            'conversationid' => (int)($conversationid ?? 0),
            'errorcode' => $key,
            'tokens' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        ];
    }
}
