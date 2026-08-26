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
 * Embeddings client for the local knowledge base.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Generates text embeddings via OpenAI or Gemini.
 *
 * Anthropic and DeepSeek expose no embeddings API, so the embeddings provider
 * is selected independently from the chat provider. When neither an OpenAI nor
 * a Gemini key is available, callers fall back to lexical retrieval.
 */
class embeddings {
    /** @var int Maximum texts per API request. */
    private const BATCH_SIZE = 64;

    /** @var int Maximum characters per embedded text. */
    private const MAX_CHARS = 8000;

    /**
     * Resolve the effective embeddings provider.
     *
     * @return string 'openai', 'gemini' or '' when embeddings are unavailable.
     */
    public static function provider(): string {
        $configured = (string)get_config('block_openaiagent', 'embeddings_provider');

        if ($configured === 'none') {
            return '';
        }
        if ($configured === 'openai' || $configured === 'gemini') {
            return self::has_key($configured) ? $configured : '';
        }

        // Auto: prefer the chat provider when it supports embeddings, then
        // any provider with a configured key.
        $chatprovider = factory::provider();
        foreach ([$chatprovider, 'openai', 'gemini'] as $candidate) {
            if (($candidate === 'openai' || $candidate === 'gemini') && self::has_key($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Whether semantic embeddings are available.
     *
     * @return bool
     */
    public static function available(): bool {
        return self::provider() !== '';
    }

    /**
     * The model id used to embed, for cache-invalidation of stored vectors.
     *
     * @return string Model id, or '' when embeddings are unavailable.
     */
    public static function model(): string {
        $provider = self::provider();
        if ($provider === '') {
            return '';
        }
        $model = trim((string)get_config('block_openaiagent', 'embeddings_model'));
        if ($model !== '') {
            return $model;
        }
        return $provider === 'gemini' ? 'gemini-embedding-001' : 'text-embedding-3-small';
    }

    /**
     * Embed a list of texts.
     *
     * @param string[] $texts Texts to embed.
     * @return array|null List of float vectors (same order), or null on failure.
     */
    public static function embed(array $texts): ?array {
        $provider = self::provider();
        if ($provider === '' || empty($texts)) {
            return null;
        }

        $texts = array_map(static function ($text): string {
            $t = \core_text::substr(trim((string)$text), 0, self::MAX_CHARS);
            // Drop invalid UTF-8 byte sequences (common in extracted PDF text).
            // A single bad byte makes json_encode() return false and poisons the
            // whole embeddings request, which would otherwise leave every chunk
            // in the batch unembedded.
            if ($t !== '' && !mb_check_encoding($t, 'UTF-8')) {
                $scrubbed = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
                $t = is_string($scrubbed) ? $scrubbed : '';
            }
            // The API rejects empty inputs, so keep a placeholder to preserve
            // batch alignment; such a chunk simply gets a throwaway vector.
            return trim($t) === '' ? ' ' : $t;
        }, array_values($texts));

        $vectors = [];
        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $result = $provider === 'gemini' ? self::embed_gemini($batch) : self::embed_openai($batch);
            if ($result === null || count($result) !== count($batch)) {
                return null;
            }
            $vectors = array_merge($vectors, $result);
        }
        return $vectors;
    }

    /**
     * Whether the given provider has an API key configured.
     *
     * @param string $provider Provider key.
     * @return bool
     */
    private static function has_key(string $provider): bool {
        $setting = $provider === 'gemini' ? 'gemini_apikey' : 'apikey';
        return trim((string)get_config('block_openaiagent', $setting)) !== '';
    }

    /**
     * Embed a batch via the OpenAI embeddings endpoint.
     *
     * @param string[] $batch Texts.
     * @return array|null Vectors or null on failure.
     */
    private static function embed_openai(array $batch): ?array {
        $baseurl = (string)get_config('block_openaiagent', 'openai_base_url');
        if ($baseurl === '') {
            $baseurl = 'https://api.openai.com/v1';
        }
        $decoded = self::post(rtrim($baseurl, '/') . '/embeddings', [
            'model' => self::model(),
            'input' => $batch,
        ], ['Authorization: Bearer ' . (string)get_config('block_openaiagent', 'apikey')]);

        if ($decoded === null || !is_array($decoded['data'] ?? null)) {
            return null;
        }
        $vectors = [];
        foreach ($decoded['data'] as $item) {
            if (!is_array($item['embedding'] ?? null)) {
                return null;
            }
            $vectors[(int)($item['index'] ?? count($vectors))] = array_map('floatval', $item['embedding']);
        }
        ksort($vectors);
        return array_values($vectors);
    }

    /**
     * Embed a batch via the Gemini batchEmbedContents endpoint.
     *
     * @param string[] $batch Texts.
     * @return array|null Vectors or null on failure.
     */
    private static function embed_gemini(array $batch): ?array {
        $baseurl = (string)get_config('block_openaiagent', 'gemini_base_url');
        if ($baseurl === '') {
            $baseurl = 'https://generativelanguage.googleapis.com/v1beta';
        }
        $model = self::model();
        $requests = array_map(static function (string $text) use ($model): array {
            return [
                'model' => 'models/' . $model,
                'content' => ['parts' => [['text' => $text]]],
            ];
        }, $batch);

        $url = rtrim($baseurl, '/') . '/models/' . rawurlencode($model) . ':batchEmbedContents';
        $decoded = self::post(
            $url,
            ['requests' => $requests],
            ['x-goog-api-key: ' . (string)get_config('block_openaiagent', 'gemini_apikey')]
        );

        if ($decoded === null || !is_array($decoded['embeddings'] ?? null)) {
            return null;
        }
        $vectors = [];
        foreach ($decoded['embeddings'] as $item) {
            if (!is_array($item['values'] ?? null)) {
                return null;
            }
            $vectors[] = array_map('floatval', $item['values']);
        }
        return $vectors;
    }

    /**
     * POST JSON and decode the response, or null on any failure.
     *
     * @param string $url Endpoint URL.
     * @param array $payload Request body.
     * @param string[] $headers Extra headers.
     * @return array|null
     */
    private static function post(string $url, array $payload, array $headers): ?array {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        $curl = new \curl();
        try {
            $raw = $curl->post($url, $body, [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 60,
                'CURLOPT_CONNECTTIMEOUT' => 15,
                'CURLOPT_HTTPHEADER' => array_merge(['Content-Type: application/json'], $headers),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($curl->get_errno() || $httpcode < 200 || $httpcode >= 300) {
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
