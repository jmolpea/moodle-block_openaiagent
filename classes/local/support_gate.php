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
 * Decides whether a conversation may be offered the support escalation.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Eligibility gate for the support escalation offer.
 *
 * Escalation is a last resort, not an answer. If this class says no, the
 * drafting tool is left out of the schema sent to the model, so the model
 * cannot offer the escalation: it does not know the tool exists. That is the
 * hard half of the double lock; the prompt rules are only the soft half.
 *
 * It is the same shape as the evaluation and live-data gates in the
 * orchestrator, but applied in reverse: instead of forcing a route, it
 * withdraws a capability.
 */
class support_gate {
    /** @var string Everything checked out; the offer may be made. */
    public const ALLOWED = 'allowed';

    /** @var string Escalation is off for the site or the course. */
    public const DENIED_DISABLED = 'disabled';

    /** @var string The participant may not raise support requests here. */
    public const DENIED_CAPABILITY = 'nocapability';

    /** @var string The participant has used up the daily allowance. */
    public const DENIED_QUOTA = 'quota';

    /** @var string The participant sent one too recently. */
    public const DENIED_COOLDOWN = 'cooldown';

    /** @var string The course has hit its daily ceiling. */
    public const DENIED_COURSECEILING = 'courseceiling';

    /** @var string A draft from this conversation is still unanswered. */
    public const DENIED_PENDING = 'pending';

    /** @var string The participant declined an offer a moment ago. */
    public const DENIED_REFUSED = 'refused';

    /** @var string Nothing suggests the assistant has failed the participant. */
    public const DENIED_NOTRIGGER = 'notrigger';

    /** @var string The participant asked to talk to a person. */
    public const TRIGGER_ASKED = 'asked_for_human';

    /** @var string The assistant already fell back in this conversation. */
    public const TRIGGER_FALLBACK = 'fallback_used';

    /** @var string A tool failed on an earlier turn. */
    public const TRIGGER_TOOLFAILURE = 'tool_failure';

    /** @var string The participant is repeating the same unresolved question. */
    public const TRIGGER_REPETITION = 'repetition';

    /** @var string The assistant itself just told them to contact support. */
    public const TRIGGER_RECOMMENDED = 'recommended_support';

    /** @var string The assistant offered to prepare the request and they said yes. */
    public const TRIGGER_ACCEPTED = 'offer_accepted';

    /**
     * Decide whether this turn may offer the escalation.
     *
     * Preconditions are evaluated first and all of them are mandatory. The quota
     * checks are deliberately among them, and not left to confirmation time
     * alone: a participant with no allowance left must never be offered
     * something that is going to fail after they click. Only then does a trigger
     * have to fire, which is what stops the offer appearing on ordinary
     * questions the assistant answers perfectly well.
     *
     * @param array $config Effective course config, as resolved by course_config.
     * @param \stdClass $conversation Conversation record.
     * @param string $message The message being handled this turn.
     * @param int $userid Participant id.
     * @param int $courseid Course id.
     * @return array{allowed: bool, reason: string, trigger: string}
     */
    public static function evaluate(
        array $config,
        \stdClass $conversation,
        string $message,
        int $userid,
        int $courseid
    ): array {
        $support = $config['support'] ?? [];
        $conversationid = (int)($conversation->id ?? 0);

        $blocked = self::hard_preconditions($config, $conversationid, $userid, $courseid);
        if ($blocked !== '') {
            return self::deny($blocked);
        }

        // An explicit request for a person overrides the silence that follows a
        // refusal: someone who has changed their mind and says so must not have
        // to argue with the assistant about it.
        $askedforhuman = self::asks_for_human($message);

        if (!$askedforhuman && self::recently_refused($conversationid, (int)($support['offercooldownturns'] ?? 0))) {
            return self::deny(self::DENIED_REFUSED);
        }

        $trigger = self::first_trigger($askedforhuman, $conversationid, $message, $config);
        if ($trigger === '') {
            return self::deny(self::DENIED_NOTRIGGER);
        }

        return [
            'allowed' => true,
            'reason' => self::ALLOWED,
            'trigger' => $trigger,
        ];
    }

    /**
     * The checks that must hold before a request may exist at all.
     *
     * Split out from evaluate() so the drafting tool can repeat them at
     * execution time. Exposure already gates the tool, but a check that only
     * lives in the caller is one refactor away from being lost, and these are
     * the ones whose absence would actually cost something: permission, the
     * feature being on, and the anti-spam ceilings.
     *
     * Deliberately excludes the post-refusal silence and the triggers. Those
     * decide whether it is a good moment to offer, which is a question about
     * conversation flow, not about safety.
     *
     * @param array $config Effective course config.
     * @param int $conversationid Conversation id.
     * @param int $userid Participant id.
     * @param int $courseid Course id.
     * @param int $ignoredraftid Draft to disregard when looking for a pending one.
     * @return string Empty when everything holds, otherwise the reason.
     */
    public static function hard_preconditions(
        array $config,
        int $conversationid,
        int $userid,
        int $courseid,
        int $ignoredraftid = 0
    ): string {
        $support = $config['support'] ?? [];

        if (empty($support['enabled'])) {
            return self::DENIED_DISABLED;
        }

        // A destination is what makes the feature real. Without one the offer
        // would end in "we could not send it", so it is treated as switched off.
        if (trim((string)($support['to'] ?? '')) === '') {
            return self::DENIED_DISABLED;
        }

        if ($courseid <= 0 || !self::participant_may_request($courseid, $userid)) {
            return self::DENIED_CAPABILITY;
        }

        if (supportrequest::has_pending_draft($conversationid, $ignoredraftid)) {
            return self::DENIED_PENDING;
        }

        $maxperuser = (int)($support['maxperuserday'] ?? 0);
        if ($maxperuser > 0 && supportrequest::count_user_today($courseid, $userid) >= $maxperuser) {
            return self::DENIED_QUOTA;
        }

        // There is deliberately no conversation-scoped guard on top of this one.
        // The cooldown is per participant and course, which is strictly wider
        // than per conversation, so a second check would only ever fire where
        // this one already has -- and the version that did, keyed to the
        // 24-hour deduplication window, locked people out of raising a second,
        // genuinely different problem for the rest of the day while telling them
        // nothing about when that would end. Repeats of the same problem are
        // caught by content, wherever they are raised from.
        $cooldown = (int)($support['cooldownminutes'] ?? 0);
        if ($cooldown > 0) {
            $since = supportrequest::seconds_since_last($courseid, $userid);
            if ($since !== null && $since < $cooldown * MINSECS) {
                return self::DENIED_COOLDOWN;
            }
        }

        $maxpercourse = (int)($support['maxpercourseday'] ?? 0);
        if ($maxpercourse > 0 && supportrequest::count_course_today($courseid) >= $maxpercourse) {
            return self::DENIED_COURSECEILING;
        }

        return '';
    }

    /**
     * Whether the participant is allowed to raise support requests here.
     *
     * @param int $courseid Course id.
     * @param int $userid Participant id.
     * @return bool
     */
    private static function participant_may_request(int $courseid, int $userid): bool {
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }

        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return false;
        }

        return has_capability('block/openaiagent:requestsupport', $context, $userid);
    }

    /**
     * Find the first trigger that fires, if any.
     *
     * @param bool $askedforhuman Whether the message asks for a person.
     * @param int $conversationid Conversation id.
     * @param string $message Current message.
     * @param array $config Effective course config.
     * @return string Trigger constant, or an empty string when none fires.
     */
    private static function first_trigger(
        bool $askedforhuman,
        int $conversationid,
        string $message,
        array $config
    ): string {
        // D1. Immediate, from the very first message. Making someone who has
        // asked for a human sit through an automated attempt first is exactly
        // the friction that makes people hate these assistants.
        if ($askedforhuman) {
            return self::TRIGGER_ASKED;
        }

        // The remaining triggers all require that the assistant has already
        // tried and failed, which is what keeps the offer off ordinary
        // conversations.
        if ($conversationid <= 0) {
            return '';
        }

        // D2b. The assistant offered to prepare the request and they accepted.
        // The mirror of D5 below: that one catches "I told you to go to support",
        // this one catches "I told you I could do it for you". Without it the
        // model holds the drafting tool, is told yes, and answers by making the
        // same offer again -- measured on a course run, a participant answered
        // "sí, quiero que lo reporten" and got the offer repeated verbatim.
        // Checked first because an accepted offer is the least ambiguous signal
        // there is, and because the turn that follows it must not be spent
        // asking again.
        if (
            self::accepts_an_offer(
                $message,
                conversation_repository::last_assistant_message($conversationid)
            )
        ) {
            return self::TRIGGER_ACCEPTED;
        }

        // D3. A tool that failed or refused permission on an earlier turn.
        // Failures within the current turn cannot be known here, because the
        // tool schema is fixed before the model runs; those are picked up on the
        // next turn, or by the server-side offer once it exists.
        if (self::had_tool_failure($conversationid)) {
            return self::TRIGGER_TOOLFAILURE;
        }

        // D2. The assistant already gave one of its configured fallbacks.
        if (self::used_fallback($conversationid, $config)) {
            return self::TRIGGER_FALLBACK;
        }

        // D4. The participant is asking the same thing again.
        if (self::is_repeating($conversationid, $message)) {
            return self::TRIGGER_REPETITION;
        }

        // D5. The assistant's own previous reply sent them to support. Saying
        // "you will have to contact support" and then not offering to do it is
        // the exact behaviour this feature exists to replace.
        if (self::recommends_support(conversation_repository::last_assistant_message($conversationid))) {
            return self::TRIGGER_RECOMMENDED;
        }

        return '';
    }

    /**
     * Whether the participant asked to be put through to a person.
     *
     * Deterministic on purpose. This is the one path that has to work on the
     * first message, before the assistant has said anything, so it cannot depend
     * on the model's judgement. Covers Spanish, English and Portuguese, the
     * three languages the rest of the plugin's detectors handle.
     *
     * @param string $message User message.
     * @return bool
     */
    public static function asks_for_human(string $message): bool {
        $text = \core_text::strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        // Naming a person or a team to be reached.
        $who = 'persona|humano|humana|alguien|agente|operador|tecnico|técnico'
            . '|soporte|asistencia|ayuda tecnica|ayuda técnica|secretaria|secretaría'
            . '|human|someone|somebody|agent|advisor|adviser|support|helpdesk|help desk'
            . '|pessoa|humano|alguem|alguém|suporte|atendente';

        // Wanting to reach, speak to, or be transferred to them.
        $reach = 'hablar|contactar|contacto|comunicar|escribir|llamar|derivar|derivame|derívame'
            . '|pasame|pásame|poner en contacto|abrir (?:una |un )?(?:incidencia|consulta|ticket)'
            . '|reportar|reclamar|escalar'
            . '|talk|speak|contact|reach|transfer|escalate|raise (?:a )?(?:ticket|issue|case)|report'
            . '|falar|contatar|abrir (?:um )?(?:chamado|ticket)';

        // Asking to open a ticket names no one, but it is unmistakably a request
        // to reach the support team, so it has to stand on its own. "Consulta"
        // is deliberately left out of this list: on its own it is far too broad
        // ("abrir una consulta en el foro"), and paired with a support word it
        // already matches through the combination below.
        $selfcontained = 'abrir (?:una |un )?(?:incidencia|ticket|caso|reclamacion|reclamación)'
            . '|poner (?:una )?(?:incidencia|reclamacion|reclamación)'
            . '|raise (?:a )?(?:ticket|issue|case)|open (?:a )?(?:ticket|case|issue)'
            . '|abrir (?:um )?chamado';

        // Handing the job to the assistant: "envia tu la solicitud", "hazlo tu",
        // "puedes mandarlo tu". These name nobody and use no reaching verb, so
        // neither pattern below sees them, yet they are among the most natural
        // ways to ask for exactly this. They were the gap that made the feature
        // feel like it only answered to magic words.
        $delegate = '(?:envia|envía|manda|escribe|solicita|solicítalo|solicitalo|reporta|tramita|gestiona|abre)'
            . '(?:lo|la|les|selo|séla)?'
            . '(?:\s+tu|\s+tú|\s+usted)?'
            . '(?:\s+(?:la|el|una|un|mi|esa|esta))?'
            . '\s*(?:solicitud|peticion|petición|incidencia|consulta|ticket|reclamacion|reclamación|caso)'
            . '|(?:hazlo|hazla|haganlo|háganlo|encargate|encárgate|ocupate|ocúpate)\s*(?:tu|tú|usted)?'
            // The pronoun already stands for the request, so no noun follows:
            // "solicitalo tu", "enviala tu". The explicit "tu" is what makes it
            // a delegation rather than an instruction the participant follows.
            . '|(?:envialo|envíalo|enviala|envíala|mandalo|mándalo|mandala|mándala'
            . '|solicitalo|solicítalo|solicitala|solicítala|tramitalo|tramítalo'
            . '|gestionalo|gestiónalo|reportalo|repórtalo|escribelo|escríbelo)'
            . '\s*(?:tu|tú|usted)'
            . '|(?:puedes|podrias|podrías|puede)\s+(?:enviar|mandar|escribir|solicitar|tramitar|gestionar)'
            . '(?:lo|la|selo|sela)?\s*(?:tu|tú)?';

        if (preg_match('/(?:' . $delegate . ')/u', $text)) {
            return true;
        }

        if (preg_match('/(?:' . $selfcontained . ')/u', $text)) {
            return true;
        }

        if (preg_match('/(?:' . $reach . ').{0,40}(?:' . $who . ')/u', $text)) {
            return true;
        }

        // The reverse order, as in "con una persona quiero hablar" or the very
        // common bare "soporte tecnico" / "atencion al cliente".
        if (preg_match('/(?:' . $who . ').{0,40}(?:' . $reach . ')/u', $text)) {
            return true;
        }

        // Short, unambiguous set phrases that carry no verb at all.
        $phrases = '/^(?:'
            . 'soporte(?:\s+tecnico|\s+técnico)?'
            . '|atencion al cliente|atención al cliente'
            . '|servicio tecnico|servicio técnico'
            . '|human support|technical support|customer support|customer service'
            . '|suporte(?:\s+tecnico|\s+técnico)?'
            . ')[\s\.\!\?]*$/u';

        return (bool)preg_match($phrases, $text);
    }

    /**
     * Whether a reply tells the participant to go and contact somebody.
     *
     * Runs over the assistant's own words, not the participant's, and it is the
     * signal that matters most in practice: the moment the assistant concludes
     * "you will have to contact support" or "ask your teacher for permission" is
     * precisely the moment to offer to do it for them. Detecting only the
     * configured fallback texts missed it, because a reply that answers the
     * question perfectly well and *then* points elsewhere is not a fallback.
     *
     * Deliberately narrow: it needs a verb about getting in touch AND somebody
     * to get in touch with. A reply that merely mentions the support team
     * ("your query is already with the support team") matches neither.
     *
     * @param string $reply Assistant reply.
     * @return bool
     */
    public static function recommends_support(string $reply): bool {
        $text = \core_text::strtolower(trim($reply));
        if ($text === '') {
            return false;
        }

        $reach = 'contacta|contactar|contacte|contactando|ponte en contacto|ponerte en contacto'
            . '|pongas en contacto|comunicate|comunícate|comunicarte|escribe a|escribir a'
            . '|dirigete a|dirígete a|dirigirte a|acude a|acudir a|habla con|hablar con'
            . '|solicita autorizacion|solicita autorización|solicitar autorizacion|solicitar autorización'
            . '|contact|get in touch|reach out to|write to|speak to|ask your'
            . '|entre em contato|fale com';

        $who = 'soporte|equipo de soporte|servicio de soporte|asistencia tecnica|asistencia técnica'
            . '|administrador|administracion|administración|mesa de ayuda'
            . '|docente|profesor|profesora|tutor|tutora|coordinacion|coordinación|coordinador'
            . '|secretaria|secretaría'
            . '|support|help ?desk|administrator|teacher|instructor|tutor'
            . '|suporte|professor';

        // Both orders, because "contacta con el docente" and "al docente debes
        // escribirle" are the same instruction.
        if (
            preg_match('/(?:' . $reach . ').{0,60}(?:' . $who . ')/u', $text)
                || preg_match('/(?:' . $who . ').{0,60}(?:' . $reach . ')/u', $text)
        ) {
            return true;
        }

        // Sending somebody to the FORM is also sending them to support, and the
        // verbs that go with a form are not the verbs that go with a person.
        // "Utiliza el formulario de Soporte técnico" and "puedes solicitarla
        // mediante el formulario" matched nothing above, which is how a
        // participant came to answer "sí, quiero que lo reporten" and get the
        // same offer again instead of a card: the trigger that opens the gate on
        // the next turn reads the previous reply, and it could not see one.
        //
        // Kept to the form and the help desk on purpose. Widening the verbs
        // against the whole "who" list above would fire on ordinary sentences
        // like "la actividad solicita una calificación del tutor".
        $useverbs = 'utiliza|utilizar|usa|usar|emplea|emplear|mediante|a trav[eé]s de|por medio de'
            . '|rellena|rellenar|completa|completar|env[ií]a|enviar|reporta|reportar|informa|informar'
            . '|solicita|solicitar|tramita|tramitar'
            . '|use|using|fill in|fill out|submit it through|report it|raise it'
            . '|utilize|utilizar o|preencha|preencher|envie|reporte';
        $channel = 'formulario|form?ulario de soporte|mesa de ayuda|help ?desk|support form'
            . '|formul[aá]rio|canal de soporte|canal de atenci[oó]n';

        return (bool)preg_match('/(?:' . $useverbs . ').{0,60}(?:' . $channel . ')/u', $text)
            || (bool)preg_match('/(?:' . $channel . ').{0,60}(?:' . $useverbs . ')/u', $text);
    }

    /**
     * Whether this message accepts an offer the assistant made in its last reply.
     *
     * Two conditions, and both are needed. The previous reply must actually have
     * offered to prepare the request -- not merely mentioned support -- and this
     * message must read as taking it up. Insistence counts as taking it up: after
     * being offered help, "pero necesito entregarla como sea" is not a new
     * question, it is a yes with feeling.
     *
     * Deliberately requires a short message. A long reply after an offer is
     * usually a new topic, and drafting a support request off the back of it
     * would be exactly the unwanted card this feature spent three versions
     * learning not to send.
     *
     * @param string $message The participant's message.
     * @param string $lastreply The assistant's previous reply.
     * @return bool
     */
    public static function accepts_an_offer(string $message, string $lastreply): bool {
        $reply = \core_text::strtolower(trim($lastreply));
        if ($reply === '') {
            return false;
        }

        // The offer, in the words the escalation directive produces.
        $offered = '/(puedo|podr[ií]a) (preparar|redactar|dejar lista|enviar)'
            . '|prepar(o|ar[eé]) (la|una) solicitud'
            . '|i can (prepare|draft|raise)|posso preparar|preparar a solicita/u';
        if (!preg_match($offered, $reply)) {
            return false;
        }

        $text = \core_text::strtolower(trim($message));
        if ($text === '' || count(preg_split('/\s+/u', $text) ?: []) > 12) {
            return false;
        }

        // An explicit no closes it; recently_refused() then keeps it closed. But
        // "no puedo" and friends are not a refusal of the offer, they are the
        // problem being restated, so they must not close anything.
        $refusal = '/(*UCP)\b(no|nope|not yet|ainda n[aã]o)\b/u';
        $problem = '/(*UCP)\bno\s+(puedo|me deja|funciona|consigo|aparece|va)\b/u';
        if (preg_match($refusal, $text) && !preg_match($problem, $text)) {
            return false;
        }

        // Unicode property support (*UCP) makes \b treat accented letters as
        // part of a word. Without it, "sí," is cut in the middle and has no
        // boundary at the end, so the commonest acceptance in the language this
        // course is taught in matches nothing at all -- which is exactly how
        // this shipped the first time.
        $yes = '/(*UCP)\b(s[ií]|claro|vale|ok|okay|dale|adelante|perfecto|porfa|'
            . 'h[aá]zlo|hazla|env[ií]ala|env[ií]alo|report[ae]|rep[oó]rtalo|'
            . 'quiero|necesito|prep[aá]rala|prep[aá]ralo|'
            . 'yes|please|go ahead|do it|send it|report it|'
            . 'sim|por favor)\b/u';
        $urgency = '/(*UCP)\b(como sea|urgente|ay[uú]dame|ayuda)\b/u';

        return (bool)preg_match($yes, $text) || (bool)preg_match($urgency, $text);
    }

    /**
     * Whether the participant declined an offer too recently to be asked again.
     *
     * Measured in the participant's own turns rather than in minutes: what makes
     * a repeated offer feel pushy is being asked again two messages later, not
     * being asked again ten minutes later.
     *
     * @param int $conversationid Conversation id.
     * @param int $turns How many of their turns must pass first.
     * @return bool
     */
    private static function recently_refused(int $conversationid, int $turns): bool {
        if ($turns <= 0) {
            return false;
        }

        $refusedat = supportrequest::last_refusal_time($conversationid);
        if ($refusedat <= 0) {
            return false;
        }

        return conversation_repository::count_user_messages_since($conversationid, $refusedat) <= $turns;
    }

    /**
     * Whether a tool failed on an earlier turn of this conversation.
     *
     * Reads the errormessage column rather than the message text: content is
     * blanked on profiles that do not store conversations, and this signal has
     * to keep working there.
     *
     * @param int $conversationid Conversation id.
     * @return bool
     */
    private static function had_tool_failure(int $conversationid): bool {
        global $DB;

        return $DB->record_exists_select(
            'block_openaiagent_messages',
            "conversationid = :cid AND role = 'tool' AND errormessage <> ''",
            ['cid' => $conversationid]
        );
    }

    /**
     * Whether the assistant has already produced one of its fallback messages.
     *
     * Compares the last reply against the course's fallback texts on their
     * normalised opening words: a fallback is usually wrapped in some extra
     * prose, so an equality test would almost never match, and the opening is
     * the part the model reproduces most faithfully.
     *
     * Returns false on profiles that do not store message text. That is a real
     * limitation and not a bug: those courses reach the offer through D1, D3 or
     * D4 instead.
     *
     * @param int $conversationid Conversation id.
     * @param array $config Effective course config.
     * @return bool
     */
    private static function used_fallback(int $conversationid, array $config): bool {
        $last = conversation_repository::last_assistant_message($conversationid);
        if (trim($last) === '') {
            return false;
        }

        $haystack = supportrequest::normalize_summary($last);
        if ($haystack === '') {
            return false;
        }

        // Read the course's own wording. Comparing against the site defaults
        // would silently never match in any course that rewrote them, which is
        // most of them.
        foreach (['fallbacknoinfo', 'fallbackoutofscope'] as $name) {
            $needle = self::fingerprint((string)($config[$name] ?? ''));
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first few normalised words of a text, used to recognise it again.
     *
     * @param string $text Source text.
     * @param int $words How many words to keep.
     * @return string Empty when the text is too short to identify anything.
     */
    public static function fingerprint(string $text, int $words = 8): string {
        $normalized = supportrequest::normalize_summary($text);
        if ($normalized === '') {
            return '';
        }

        $parts = array_slice(explode(' ', $normalized), 0, $words);
        // Too few words to be distinctive: matching on them would fire on almost
        // any reply, which is worse than never firing.
        if (count($parts) < 4) {
            return '';
        }

        return implode(' ', $parts);
    }

    /**
     * Whether the participant is asking the same thing again.
     *
     * @param int $conversationid Conversation id.
     * @param string $message Current message.
     * @return bool
     */
    private static function is_repeating(int $conversationid, string $message): bool {
        $current = self::fingerprint($message, 12);
        if ($current === '') {
            return false;
        }

        // The current message is already stored by the time the gate runs, so the
        // window has to be wide enough to look past it.
        $previous = conversation_repository::recent_user_messages($conversationid, 6);
        $matches = 0;
        foreach ($previous as $text) {
            if (self::fingerprint($text, 12) === $current) {
                $matches++;
            }
        }

        // Two or more occurrences means they have asked and asked again.
        return $matches >= 2;
    }

    /**
     * Build a denial.
     *
     * @param string $reason Reason constant.
     * @return array{allowed: bool, reason: string, trigger: string}
     */
    private static function deny(string $reason): array {
        return [
            'allowed' => false,
            'reason' => $reason,
            'trigger' => '',
        ];
    }
}
