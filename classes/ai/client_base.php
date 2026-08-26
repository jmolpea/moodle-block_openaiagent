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
 * Base class for AI provider clients.
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
 * Shared HTTP plumbing for provider adapters.
 *
 * Never logs the API key. All adapters return a normalized {@see response}.
 */
abstract class client_base {
    /** @var string Provider API key. */
    protected string $apikey;

    /** @var string API base URL (no trailing slash). */
    protected string $baseurl;

    /** @var int Request timeout in seconds. */
    protected int $timeout;

    /**
     * Constructor.
     *
     * @param string $apikey Provider API key.
     * @param string $baseurl Base URL (empty = adapter default).
     * @param int $timeout Timeout in seconds.
     */
    public function __construct(string $apikey, string $baseurl = '', int $timeout = 60) {
        $this->apikey = $apikey;
        if ($baseurl === '') {
            $baseurl = $this->default_base_url();
        }
        $this->baseurl = rtrim($baseurl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Whether the client has a usable API key.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    /**
     * Execute a chat completion.
     *
     * @param request $request Neutral request.
     * @return response Normalized response.
     */
    abstract public function complete(request $request): response;

    /**
     * The adapter's default model id.
     *
     * @return string
     */
    abstract public function default_model(): string;

    /**
     * The adapter's default API base URL.
     *
     * @return string
     */
    abstract public function default_base_url(): string;

    /**
     * Whether a model id plausibly belongs to this provider.
     *
     * Used to protect against configuration left over from another provider
     * (e.g. a gpt-* model while Anthropic is active).
     *
     * @param string $model Model id.
     * @return bool
     */
    abstract public function owns_model(string $model): bool;

    /**
     * POST a JSON payload and decode the JSON response.
     *
     * @param string $url Full endpoint URL.
     * @param array $payload Request body.
     * @param string[] $headers Extra HTTP headers (Content-Type is always added).
     * @return array [int $httpcode, array|null $decoded, string $transporterror]
     */
    protected function post_json(string $url, array $payload, array $headers): array {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return [0, null, 'encodefailed'];
        }

        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_HTTPHEADER' => array_merge(['Content-Type: application/json'], $headers),
        ];

        try {
            $raw = $curl->post($url, $body, $options);
        } catch (\Throwable $e) {
            return [0, null, $e->getMessage()];
        }

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

        if ($curl->get_errno()) {
            return [$httpcode, null, 'cURL error ' . $curl->get_errno()];
        }

        $decoded = json_decode((string)$raw, true);
        self::log_payload($url, $body, (string)$raw, $httpcode);
        return [$httpcode, is_array($decoded) ? $decoded : null, ''];
    }

    /**
     * Write the raw request and response to the debug log when asked to.
     *
     * Placed here because every provider goes through post_json(), so one call
     * site covers OpenAI, Anthropic, Gemini and DeepSeek. Output goes to
     * debugging() rather than a table: these payloads carry the participant's
     * message and the model's reply verbatim, and persisting them would create a
     * second store of personal data outside the privacy provider's reach. The
     * setting says "never enable in production" and this keeps that true --
     * the data lands in the server error log and nowhere else.
     *
     * Authorization headers are never passed to this method, but the payload can
     * still carry a key on providers that put it in the body, so the redaction
     * runs over the request either way.
     *
     * @param string $url Endpoint called.
     * @param string $body Raw request body.
     * @param string $raw Raw response body.
     * @param int $httpcode HTTP status code.
     * @return void
     */
    private static function log_payload(string $url, string $body, string $raw, int $httpcode): void {
        if ((int)get_config('block_openaiagent', 'log_payloads') !== 1) {
            return;
        }

        debugging(
            'block_openaiagent payload: url=' . $url
                . '; http=' . $httpcode
                . '; request=' . self::redact_secrets($body)
                . '; response=' . self::redact_secrets($raw),
            DEBUG_DEVELOPER
        );
    }

    /**
     * Mask anything that looks like an API key in a payload.
     *
     * @param string $text Payload text.
     * @return string
     */
    private static function redact_secrets(string $text): string {
        return (string)preg_replace(
            '/\b(sk-|AIza)[A-Za-z0-9_\-]{10,}/',
            '$1[REDACTED]',
            $text
        );
    }

    /**
     * Map an HTTP failure to a normalized error response.
     *
     * @param int $httpcode HTTP status code.
     * @param string $message Provider error message.
     * @return response
     */
    protected static function http_failure(int $httpcode, string $message): response {
        $code = 'apierror';
        if ($httpcode === 401 || $httpcode === 403) {
            $code = 'invalidapikey';
        } else if ($httpcode === 429) {
            $code = 'ratelimited';
        }
        return response::failure($code, $message !== '' ? $message : ('HTTP ' . $httpcode), $httpcode);
    }
}
