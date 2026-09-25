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
 * Chat Completions adapter for OpenAI and OpenAI-compatible providers (DeepSeek).
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Adapter for the OpenAI Chat Completions wire format.
 *
 * Serves both the 'openai' and 'deepseek' providers; only the base URL,
 * default model and token-limit parameter name differ.
 */
class openai_compatible_client extends client_base {
    /** @var string Provider key: 'openai' or 'deepseek'. */
    private string $provider;

    /**
     * Constructor.
     *
     * @param string $provider Provider key ('openai' or 'deepseek').
     * @param string $apikey Provider API key.
     * @param string $baseurl Base URL (empty = provider default).
     * @param int $timeout Timeout in seconds.
     */
    public function __construct(string $provider, string $apikey, string $baseurl = '', int $timeout = 60) {
        $this->provider = $provider === 'deepseek' ? 'deepseek' : 'openai';
        parent::__construct($apikey, $baseurl, $timeout);
    }

    /**
     * The adapter's default model id.
     *
     * @return string
     */
    public function default_model(): string {
        return $this->provider === 'deepseek' ? 'deepseek-flash' : 'gpt-4.1-mini';
    }

    /**
     * The adapter's default API base URL.
     *
     * @return string
     */
    public function default_base_url(): string {
        return $this->provider === 'deepseek' ? 'https://api.deepseek.com/v1' : 'https://api.openai.com/v1';
    }

    /**
     * Whether a model id plausibly belongs to this provider.
     *
     * @param string $model Model id.
     * @return bool
     */
    public function owns_model(string $model): bool {
        $model = strtolower($model);
        if ($this->provider === 'deepseek') {
            return strpos($model, 'deepseek') === 0;
        }
        foreach (['gpt-', 'o1', 'o3', 'o4', 'chatgpt'] as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The thinking-mode fields for a DeepSeek request.
     *
     * DeepSeek's current models think by default, at high effort. That is fine
     * for a free-form answer, but it breaks the assistant: with thinking on, a
     * request that carries tools must send every earlier reasoning_content back
     * on each following request, or the API answers 400. The tool loop does not
     * keep those, so the second call of any turn that looked something up would
     * fail. Thinking is therefore switched off whenever tools are sent -- the
     * same decision, for the same reason, as reasoning_effort 'none' on gpt-6.
     *
     * It is also switched off in JSON mode, which only the router uses: a short
     * classifier that runs on every turn, where seconds of reasoning buy nothing.
     *
     * Otherwise the site's effort setting is passed through. DeepSeek maps the
     * neutral values itself (minimal to low, medium to high), and an empty
     * setting sends nothing, leaving the provider's default in place.
     *
     * @param request $request Neutral request.
     * @return array Payload fields to add.
     */
    private static function deepseek_thinking(request $request): array {
        if (!empty($request->tools) || $request->jsonmode) {
            return ['thinking' => ['type' => 'disabled']];
        }
        if ($request->reasoningeffort !== '') {
            return ['reasoning_effort' => $request->reasoningeffort];
        }
        return [];
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

        $messages = [['role' => 'system', 'content' => $request->instructions]];
        foreach ($request->messages as $message) {
            $messages[] = self::convert_message($message);
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
        ];
        // OpenAI reasoning models (gpt-5*, gpt-6*, o1/o3/o4*) reject any temperature
        // other than the default, so the parameter must be omitted for them.
        // DeepSeek has its own branch: see deepseek_thinking().
        $reasoningmodel = $this->provider === 'openai'
            && preg_match('/^(gpt-[56]|o\d)/', strtolower($request->model)) === 1;
        if ($this->provider === 'deepseek') {
            $payload['temperature'] = $request->temperature;
            $payload += self::deepseek_thinking($request);
        } else if (!$reasoningmodel) {
            $payload['temperature'] = $request->temperature;
        } else if (self::rejects_tools_with_reasoning($request->model) && !empty($request->tools)) {
            // The gpt-5.6 and gpt-6 families refuse function tools combined with a reasoning
            // effort on this endpoint, and says exactly what to do instead:
            // "Function tools with reasoning_effort are not supported for
            // gpt-5.6-luna in /v1/chat/completions. To use function tools, use
            // /v1/responses or set reasoning_effort to 'none'." The assistant is
            // the only route that sends tools, so selecting one of these models
            // broke every assistant turn with a 400 while the tutor kept working.
            //
            // Sent explicitly rather than omitted: leaving the parameter out
            // falls back to the model's own default effort, which is not 'none',
            // so the request would still be refused.
            $payload['reasoning_effort'] = 'none';
        } else if ($request->reasoningeffort !== '') {
            $payload['reasoning_effort'] = $request->reasoningeffort;
        }
        // DeepSeek does not accept the newer parameter name yet.
        if ($this->provider === 'deepseek') {
            $payload['max_tokens'] = $request->maxtokens;
        } else {
            $payload['max_completion_tokens'] = $request->maxtokens;
        }
        if ($request->jsonmode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if (!empty($request->tools)) {
            $payload['tools'] = array_map(static function (array $tool): array {
                return [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['parameters'],
                    ],
                ];
            }, array_values($request->tools));
        }

        [$httpcode, $decoded, $transporterror] = $this->post_json(
            $this->baseurl . '/chat/completions',
            $payload,
            ['Authorization: Bearer ' . $this->apikey]
        );

        if ($transporterror !== '') {
            return response::failure('transport', $transporterror, $httpcode);
        }
        if ($decoded === null) {
            return response::failure('badjson', 'Invalid JSON from provider.', $httpcode);
        }
        if ($httpcode < 200 || $httpcode >= 300 || isset($decoded['error'])) {
            $message = '';
            if (isset($decoded['error']['message'])) {
                $message = (string)$decoded['error']['message'];
            }
            return self::http_failure($httpcode, $message);
        }

        $choice = $decoded['choices'][0]['message'] ?? [];
        $text = trim((string)($choice['content'] ?? ''));

        $toolcalls = [];
        foreach (($choice['tool_calls'] ?? []) as $call) {
            if (!is_array($call) || ($call['type'] ?? 'function') !== 'function') {
                continue;
            }
            $arguments = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
            $toolcalls[] = [
                'id' => (string)($call['id'] ?? uniqid('call_', true)),
                'name' => (string)($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        return response::success(
            (string)($decoded['id'] ?? ''),
            $text,
            $toolcalls,
            (int)($usage['prompt_tokens'] ?? 0),
            (int)($usage['completion_tokens'] ?? 0),
            (int)($usage['total_tokens'] ?? 0),
            self::cached_tokens($usage)
        );
    }

    /**
     * Does this model refuse function tools alongside a reasoning effort?
     *
     * A property of the Chat Completions endpoint, not of the model: the same
     * model accepts both through /v1/responses. Kept as a named check rather
     * than an inline pattern so the next model with the restriction is one line
     * to add, and so the reason is documented where it is applied.
     *
     * @param string $model Model id.
     * @return bool
     */
    private static function rejects_tools_with_reasoning(string $model): bool {
        return preg_match('/^gpt-(5\.6|6)/', strtolower($model)) === 1;
    }

    /**
     * Extract the cached share of the prompt tokens from a usage block.
     *
     * Both providers cache long prompts automatically and bill those tokens at a
     * fraction of the input rate, but they report it differently: OpenAI nests
     * it under prompt_tokens_details.cached_tokens, DeepSeek exposes a flat
     * prompt_cache_hit_tokens. In both the value is already contained in
     * prompt_tokens, so it is returned as-is.
     *
     * @param array $usage Provider usage block.
     * @return int Cached prompt tokens (0 when the provider reports none).
     */
    private static function cached_tokens(array $usage): int {
        if (isset($usage['prompt_tokens_details']['cached_tokens'])) {
            return (int)$usage['prompt_tokens_details']['cached_tokens'];
        }
        return (int)($usage['prompt_cache_hit_tokens'] ?? 0);
    }

    /**
     * Convert a neutral message to the Chat Completions shape.
     *
     * @param array $message Neutral message.
     * @return array
     */
    private static function convert_message(array $message): array {
        $role = (string)($message['role'] ?? 'user');

        if ($role === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => (string)($message['toolcallid'] ?? ''),
                'content' => (string)($message['content'] ?? ''),
            ];
        }

        if ($role === 'assistant' && !empty($message['toolcalls'])) {
            $calls = [];
            foreach ($message['toolcalls'] as $call) {
                $calls[] = [
                    'id' => (string)$call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => (string)$call['name'],
                        'arguments' => json_encode($call['arguments'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
            $converted = ['role' => 'assistant', 'tool_calls' => $calls];
            if (trim((string)($message['content'] ?? '')) !== '') {
                $converted['content'] = (string)$message['content'];
            }
            return $converted;
        }

        return [
            'role' => $role === 'assistant' ? 'assistant' : 'user',
            'content' => (string)($message['content'] ?? ''),
        ];
    }
}
