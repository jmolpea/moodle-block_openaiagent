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
 * Google Gemini generateContent adapter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Adapter for the Google Gemini API (generateContent).
 */
class gemini_client extends client_base {
    /**
     * The adapter's default model id.
     *
     * @return string
     */
    public function default_model(): string {
        return 'gemini-2.5-flash';
    }

    /**
     * The adapter's default API base URL.
     *
     * @return string
     */
    public function default_base_url(): string {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * Whether a model id plausibly belongs to this provider.
     *
     * @param string $model Model id.
     * @return bool
     */
    public function owns_model(string $model): bool {
        return strpos(strtolower($model), 'gemini') === 0;
    }

    /**
     * Map the neutral reasoning effort onto a Gemini thinking budget, in tokens.
     *
     * @param request $request Neutral request.
     * @return int|null Budget, or null to omit thinkingConfig entirely.
     */
    private static function thinking_budget(request $request): ?int {
        $effort = strtolower(trim($request->reasoningeffort));
        if (!self::supports_thinking($request->model)) {
            return null;
        }
        $budgets = ['minimal' => 0, 'low' => 512, 'medium' => 2048, 'high' => 8192];
        if (!isset($budgets[$effort])) {
            return null;
        }
        $budget = $budgets[$effort];
        // Flash can switch thinking off with a budget of 0; Pro cannot, and
        // rejects anything below its floor.
        if ($budget < 128 && strpos(strtolower($request->model), 'pro') !== false) {
            $budget = 128;
        }
        return $budget;
    }

    /**
     * Whether a model supports a thinking budget. Thinking arrived with the 2.5
     * generation; 2.0 and earlier reject thinkingConfig.
     *
     * @param string $model Model id.
     * @return bool
     */
    private static function supports_thinking(string $model): bool {
        if (preg_match('/^gemini-(\d+)\.(\d+)/', strtolower($model), $m) !== 1) {
            return false;
        }
        return ((int)$m[1] * 10 + (int)$m[2]) >= 25;
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

        $generationconfig = [
            'temperature' => $request->temperature,
            'maxOutputTokens' => $request->maxtokens,
        ];
        if ($request->jsonmode) {
            $generationconfig['responseMimeType'] = 'application/json';
        }
        $budget = self::thinking_budget($request);
        if ($budget !== null) {
            $generationconfig['thinkingConfig'] = ['thinkingBudget' => $budget];
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $request->instructions]]],
            'contents' => self::convert_messages($request->messages),
            'generationConfig' => $generationconfig,
        ];
        if (!empty($request->tools)) {
            $declarations = array_map(static function (array $tool): array {
                return [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => self::clean_schema($tool['parameters']),
                ];
            }, array_values($request->tools));
            $payload['tools'] = [['functionDeclarations' => $declarations]];
        }

        $url = $this->baseurl . '/models/' . rawurlencode($request->model) . ':generateContent';
        [$httpcode, $decoded, $transporterror] = $this->post_json(
            $url,
            $payload,
            ['x-goog-api-key: ' . $this->apikey]
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

        $parts = [];
        $toolcalls = [];
        $candidate = $decoded['candidates'][0] ?? [];
        foreach (($candidate['content']['parts'] ?? []) as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text'])) {
                $parts[] = (string)$part['text'];
            } else if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                // Gemini has no tool-call ids; synthesize one so the neutral
                // loop can correlate results.
                $toolcalls[] = [
                    'id' => uniqid('gcall_', true),
                    'name' => (string)($part['functionCall']['name'] ?? ''),
                    'arguments' => is_array($part['functionCall']['args'] ?? null)
                        ? $part['functionCall']['args'] : [],
                ];
            }
        }

        // The cachedContentTokenCount field is already part of promptTokenCount,
        // so it is passed through as the cached subset.
        $usage = is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];
        return response::success(
            (string)($decoded['responseId'] ?? ''),
            trim(implode("\n", $parts)),
            $toolcalls,
            (int)($usage['promptTokenCount'] ?? 0),
            (int)($usage['candidatesTokenCount'] ?? 0),
            (int)($usage['totalTokenCount'] ?? 0),
            (int)($usage['cachedContentTokenCount'] ?? 0)
        );
    }

    /**
     * Convert neutral messages to Gemini contents.
     *
     * Consecutive tool results are merged into a single user turn holding
     * several functionResponse parts.
     *
     * @param array[] $messages Neutral messages.
     * @return array[]
     */
    private static function convert_messages(array $messages): array {
        $contents = [];

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? 'user');

            if ($role === 'tool') {
                $result = json_decode((string)($message['content'] ?? ''), true);
                $part = [
                    'functionResponse' => [
                        'name' => (string)($message['name'] ?? ''),
                        'response' => is_array($result) ? $result : ['result' => (string)($message['content'] ?? '')],
                    ],
                ];
                $last = count($contents) - 1;
                if (
                    $last >= 0 && $contents[$last]['role'] === 'user'
                        && isset($contents[$last]['parts'][0]['functionResponse'])
                ) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolcalls'])) {
                $parts = [];
                if (trim((string)($message['content'] ?? '')) !== '') {
                    $parts[] = ['text' => (string)$message['content']];
                }
                foreach ($message['toolcalls'] as $call) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => (string)$call['name'],
                            'args' => $call['arguments'] ?: new \stdClass(),
                        ],
                    ];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string)($message['content'] ?? '')]],
            ];
        }

        return $contents;
    }

    /**
     * Strip JSON Schema keywords the Gemini API rejects.
     *
     * @param array $schema JSON Schema fragment.
     * @return array
     */
    private static function clean_schema(array $schema): array {
        unset($schema['$schema'], $schema['additionalProperties']);
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::clean_schema($value);
            }
        }
        return $schema;
    }
}
