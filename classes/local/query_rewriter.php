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
 * Conditional retrieval-query rewriting for the course tutor.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\ai\client_base;
use block_openaiagent\ai\factory;
use block_openaiagent\ai\request;

/**
 * Expands weak retrieval queries with a cheap model call before re-retrieving.
 *
 * Mirrors what hosted "file search" tools do implicitly: the model reformulates
 * the user's question (resolving pronouns from the conversation, adding synonyms
 * and likely document phrasing) so short or vague questions still embed with
 * enough signal. It is invoked conditionally — only when the message itself is
 * vague or the first retrieval pass came back weak — so most turns cost nothing
 * extra.
 */
class query_rewriter {
    /** @var int Maximum characters of the expanded query. */
    private const MAX_QUERY_CHARS = 500;

    /** @var int Maximum characters per conversation turn shown to the rewriter. */
    private const MAX_TURN_CHARS = 300;

    /** @var int Recent conversation turns given to the rewriter as context. */
    private const HISTORY_TURNS = 6;

    /** @var string Rewriter instructions. */
    private const INSTRUCTIONS = <<<'EOT'
You expand a student's chat message into a standalone search query used to retrieve
excerpts from course documents (semantic embeddings + keyword search). Rules:
- Write the query in the same language as the student's message.
- Resolve pronouns and vague references ("that", "eso", "it") using the conversation.
- Keep every distinctive term of the original message (acronyms, names, sigla) and add
  synonyms, expansions and phrasing likely to appear in course documents.
- Output ONLY the expanded query as a single line of plain text. No quotes, no labels,
  no explanations.
- If the message is not an information request (a greeting, thanks, small talk), output
  the original message unchanged.
EOT;

    /**
     * Whether conditional query rewriting is enabled.
     *
     * Unset config (site upgraded before the setting existed) counts as enabled;
     * the admin checkbox stores '0' to disable.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (string)get_config('block_openaiagent', 'enable_query_rewrite') !== '0';
    }

    /**
     * Is the message too vague to retrieve on directly?
     *
     * Catches: extremely short messages ("¿y eso?"), messages with no
     * identifiable search terms, and short deictic follow-ups that lean on a
     * pronoun ("¿para qué sirve eso?").
     *
     * @param string $message Current user message.
     * @return bool
     */
    public static function is_vague(string $message): bool {
        $message = trim($message);
        if ($message === '') {
            return false;
        }
        $words = count(preg_split('/\s+/u', $message) ?: []);
        if ($words <= 4) {
            return true;
        }
        if (count(rag::tokenize($message)) <= 1) {
            return true;
        }
        $deictic = '/\b(eso|esa|ese|esto|esta|este|aquello|ello|lo anterior|lo de antes|lo mismo'
            . '|that|it|this|the same|isso|aquilo|disso)\b/iu';
        return $words <= 9 && preg_match($deictic, \core_text::strtolower($message)) === 1;
    }

    /**
     * Did retrieval come back too weak to trust?
     *
     * Weak means: nothing retrieved, a best score under the threshold for the
     * scoring mode in use, or none of the query's main terms appearing in any
     * retrieved chunk (the results are "about something else").
     *
     * @param array $diagnosed Result of {@see rag::retrieve_diagnosed}.
     * @param string $query Query that produced it.
     * @return bool
     */
    public static function is_weak_retrieval(array $diagnosed, string $query): bool {
        if (empty($diagnosed['chunks'])) {
            return true;
        }
        $topscore = (float)($diagnosed['topscore'] ?? 0.0);
        if (!empty($diagnosed['semantic'])) {
            // Hybrid scores are cosine (~0..1) + up to 0.4 of boosts; below 0.35
            // the best match is barely related to the question.
            if ($topscore < 0.35) {
                return true;
            }
        } else if ($topscore < 8.0) {
            // Lexical scores scale with matched-term length; a top score under
            // ~8 means only one short term matched anywhere.
            return true;
        }
        // No main query term appears in any retrieved chunk: the results are
        // similar to each other but not to the question.
        $hasmainterms = !empty(array_filter(rag::tokenize($query), static function (string $term): bool {
            return \core_text::strlen($term) >= 4;
        }));
        return $hasmainterms && (float)($diagnosed['coverage'] ?? 1.0) <= 0.0;
    }

    /**
     * Expand the user's message into a retrieval query using a cheap model.
     *
     * @param client_base $client Provider client (the orchestrator's client, so
     *        the rewrite always runs on the active/injected provider).
     * @param string $message Current user message.
     * @param \stdClass $conversation Conversation record (for pronoun context).
     * @return string Expanded query, or '' when rewriting failed or is unavailable.
     */
    public static function rewrite(client_base $client, string $message, \stdClass $conversation): string {
        if (!$client->is_configured()) {
            return '';
        }

        $request = new request();
        $request->model = self::model($client);
        $request->instructions = self::INSTRUCTIONS;
        // Deterministic on purpose. At 0.2 the same question produced a different
        // rewrite on each attempt, hence a different set of excerpts and a
        // different answer: asked twice in a row whether a technique was in the
        // material, the tutor said no the first time and yes the second. A
        // retrieval helper has no business being creative.
        $request->temperature = 0.0;
        $request->maxtokens = 500;
        // Reasoning-capable cheap models (gpt-5-nano) must not burn the budget
        // thinking about a one-line rewrite; adapters ignore this when the
        // model does not support it.
        $request->reasoningeffort = 'minimal';
        $request->add_user_message(self::build_prompt($message, $conversation));

        $response = $client->complete($request);

        // Billed like any other call even though it never becomes a message, so
        // its usage is recorded for the cost dashboard.
        conversation_repository::record_internal_usage(
            (int)$conversation->id,
            'rewriter',
            $request->model,
            $response
        );

        if (!$response->success) {
            return '';
        }

        $query = trim((string)$response->text);
        // Keep the first line only and strip wrapping quotes/labels.
        $lines = preg_split('/\R/u', $query) ?: [];
        $query = trim((string)($lines[0] ?? ''), " \t\"'«»");
        if ($query === '') {
            return '';
        }
        return \core_text::substr($query, 0, self::MAX_QUERY_CHARS);
    }

    /**
     * Resolve the rewriter model: the configured override when it belongs to
     * the active provider, else a cheap per-provider default.
     *
     * @param client_base $client Provider client.
     * @return string Model id.
     */
    public static function model(client_base $client): string {
        $configured = trim((string)get_config('block_openaiagent', 'query_rewrite_model'));
        if ($configured !== '' && $client->owns_model($configured)) {
            return $configured;
        }

        $defaults = [
            'openai' => 'gpt-5-nano',
            'anthropic' => 'claude-haiku-4-5',
            'gemini' => 'gemini-2.5-flash-lite',
            'deepseek' => 'deepseek-chat',
        ];
        $candidate = $defaults[factory::provider()] ?? '';
        if ($candidate !== '' && $client->owns_model($candidate)) {
            return $candidate;
        }
        return $client->default_model();
    }

    /**
     * Build the rewriter's user prompt: compact recent conversation + message.
     *
     * @param string $message Current user message.
     * @param \stdClass $conversation Conversation record.
     * @return string
     */
    private static function build_prompt(string $message, \stdClass $conversation): string {
        $lines = [];
        $history = conversation_repository::recent_history((int)$conversation->id, self::HISTORY_TURNS);
        foreach ($history as $turn) {
            $content = trim((string)$turn['content']);
            if ($content === '' || ($turn['role'] === 'user' && $content === trim($message))) {
                continue;
            }
            $label = $turn['role'] === 'user' ? 'Student' : 'Tutor';
            $lines[] = $label . ': ' . \core_text::substr($content, 0, self::MAX_TURN_CHARS);
        }

        $prompt = '';
        if (!empty($lines)) {
            $prompt .= "Conversation so far:\n" . implode("\n", $lines) . "\n\n";
        }
        $prompt .= 'Student message to expand into a search query: ' . trim($message);
        return $prompt;
    }
}
