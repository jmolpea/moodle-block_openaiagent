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
 * Anthropic Messages API adapter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Adapter for the Anthropic Messages API (Claude models).
 */
class anthropic_client extends client_base {
    /** @var string Pinned API version header value. */
    private const API_VERSION = '2023-06-01';

    /**
     * The adapter's default model id.
     *
     * @return string
     */
    public function default_model(): string {
        return 'claude-haiku-4-5';
    }

    /**
     * The adapter's default API base URL.
     *
     * @return string
     */
    public function default_base_url(): string {
        return 'https://api.anthropic.com/v1';
    }

    /**
     * Whether a model id plausibly belongs to this provider.
     *
     * @param string $model Model id.
     * @return bool
     */
    public function owns_model(string $model): bool {
        return strpos(strtolower($model), 'claude') === 0;
    }

    /**
     * Map the neutral reasoning effort onto Anthropic extended thinking.
     *
     * "minimal" deliberately returns null rather than the smallest budget: on
     * this API the cheapest, fastest option is not to enable thinking at all.
     *
     * @param request $request Neutral request.
     * @return array|null Thinking block, or null to omit it.
     */
    private static function thinking_config(request $request): ?array {
        $effort = strtolower(trim($request->reasoningeffort));
        if (!self::supports_thinking($request->model)) {
            return null;
        }
        // 1024 is the API minimum budget.
        $budgets = ['low' => 1024, 'medium' => 4096, 'high' => 8192];
        if (!isset($budgets[$effort])) {
            return null;
        }
        return ['type' => 'enabled', 'budget_tokens' => $budgets[$effort]];
    }

    /**
     * Whether a model supports extended thinking. It arrived with Claude 3.7 and
     * is present in every 4.x and 5.x model.
     *
     * @param string $model Model id.
     * @return bool
     */
    private static function supports_thinking(string $model): bool {
        return preg_match('/^claude-(3-7|(sonnet|haiku|opus)-[4-9])/', strtolower($model)) === 1;
    }

    /**
     * Execute a chat completion.
     *
     * @param request $request Neutral request.
     * @return response Normalized response.
     */
    public function complete(request $request): response {
        if (!$this->is_configured()) {
            return response::failure('noapikey', 'API key is not configured.');
        }

        $system = $request->instructions;
        $messages = self::convert_messages($request->messages);

        // The Messages API has no native JSON output mode. Combine an explicit
        // instruction with an assistant prefill of "{" (the documented pattern),
        // then re-prepend the brace to the returned text.
        if ($request->jsonmode) {
            $system .= "\n\nYou must respond with a single valid JSON object and nothing else.";
            $messages[] = ['role' => 'assistant', 'content' => '{'];
        }

        $payload = [
            'model' => $request->model,
            'system' => $system,
            'messages' => $messages,
            'max_tokens' => $request->maxtokens,
            // Anthropic accepts temperatures in [0, 1] only.
            'temperature' => min(1.0, max(0.0, $request->temperature)),
        ];
        $thinking = self::thinking_config($request);
        if ($thinking !== null) {
            $payload['thinking'] = $thinking;
            // Extended thinking has two hard API constraints: the sampling
            // temperature must be the default (1), and max_tokens must leave room
            // for the answer on top of the thinking budget. Honouring the
            // configured temperature or cap here would make the request 400.
            $payload['temperature'] = 1.0;
            $payload['max_tokens'] = max($request->maxtokens, $thinking['budget_tokens'] + 1024);
        }
        if (!empty($request->tools)) {
            $payload['tools'] = array_map(static function (array $tool): array {
                return [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['parameters'],
                ];
            }, array_values($request->tools));
        }

        [$httpcode, $decoded, $transporterror] = $this->post_json(
            $this->baseurl . '/messages',
            $payload,
            [
                'x-api-key: ' . $this->apikey,
                'anthropic-version: ' . self::API_VERSION,
            ]
        );

        if ($transporterror !== '') {
            return response::failure('transport', $transporterror, $httpcode);
        }
        if ($decoded === null) {
            return response::failure('badjson', 'Invalid JSON from provider.', $httpcode);
        }
        if ($httpcode < 200 || $httpcode >= 300 || ($decoded['type'] ?? '') === 'error') {
            $message = '';
            if (isset($decoded['error']['message'])) {
                $message = (string)$decoded['error']['message'];
            }
            return self::http_failure($httpcode, $message);
        }

        $parts = [];
        $toolcalls = [];
        foreach (($decoded['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string)$block['text'];
            } else if (($block['type'] ?? '') === 'tool_use') {
                $toolcalls[] = [
                    'id' => (string)($block['id'] ?? uniqid('toolu_', true)),
                    'name' => (string)($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        $text = trim(implode("\n", $parts));
        if ($request->jsonmode && $text !== '' && $text[0] !== '{') {
            $text = '{' . $text;
        }

        // Anthropic reports cached input OUTSIDE input_tokens (unlike every other
        // provider here), so the cached and cache-writing tokens are folded back
        // into the prompt total. Without that, a cache hit would look like a drop
        // in input tokens rather than a cheaper one, and the dashboard would
        // undercount the prompt it actually paid for.
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $cacheread = (int)($usage['cache_read_input_tokens'] ?? 0);
        $cachewrite = (int)($usage['cache_creation_input_tokens'] ?? 0);
        $prompttokens = (int)($usage['input_tokens'] ?? 0) + $cacheread + $cachewrite;
        return response::success(
            (string)($decoded['id'] ?? ''),
            $text,
            $toolcalls,
            $prompttokens,
            (int)($usage['output_tokens'] ?? 0),
            0,
            $cacheread
        );
    }

    /**
     * Convert neutral messages to the Messages API shape.
     *
     * Consecutive tool results are merged into a single user message, as the
     * API requires tool_result blocks to follow the assistant tool_use turn.
     *
     * @param array[] $messages Neutral messages.
     * @return array[]
     */
    private static function convert_messages(array $messages): array {
        $converted = [];

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? 'user');

            if ($role === 'tool') {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string)($message['toolcallid'] ?? ''),
                    'content' => (string)($message['content'] ?? ''),
                ];
                $last = count($converted) - 1;
                if ($last >= 0 && $converted[$last]['role'] === 'user' && is_array($converted[$last]['content'])) {
                    $converted[$last]['content'][] = $block;
                } else {
                    $converted[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolcalls'])) {
                $blocks = [];
                if (trim((string)($message['content'] ?? '')) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string)$message['content']];
                }
                foreach ($message['toolcalls'] as $call) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string)$call['id'],
                        'name' => (string)$call['name'],
                        'input' => $call['arguments'] ?: new \stdClass(),
                    ];
                }
                $converted[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            $converted[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => (string)($message['content'] ?? ''),
            ];
        }

        return $converted;
    }
}
