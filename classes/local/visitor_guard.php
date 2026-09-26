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
 * The protections around the assistant for visitors who are not logged in.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Keeps an anonymous chat from spending the site's AI budget unchecked.
 *
 * A visitor endpoint has no Moodle session and no sesskey, and every message it
 * accepts is paid for with the site's provider key. What stands in their place:
 *
 * - A page token, signed with a secret only this site holds, issued when the
 *   block is drawn. A script cannot talk to the endpoint without loading a page
 *   that shows the block, and a token names the block it was issued for.
 * - A per-address message limit, keyed by a keyed hash of the address, so the
 *   address itself is never stored.
 * - A site-wide daily ceiling. Once reached, visitors get a fixed message with
 *   the log-in, sign-up and support links and the provider is not called.
 * - An optional invisible captcha (Cloudflare Turnstile or reCAPTCHA v3),
 *   verified on the server before any message is processed.
 * - A random conversation token handed to the browser, so a visitor can only
 *   continue their own conversation and never guess somebody else's.
 */
final class visitor_guard {
    /** @var int Lifetime of a page token, in seconds. */
    public const PAGE_TOKEN_LIFETIME = 4 * HOURSECS;

    /** @var string No captcha. */
    public const CAPTCHA_NONE = '';

    /** @var string Cloudflare Turnstile, invisible. */
    public const CAPTCHA_TURNSTILE = 'turnstile';

    /** @var string Google reCAPTCHA v3 (score based, invisible). */
    public const CAPTCHA_RECAPTCHA = 'recaptchav3';

    /** @var string Action name sent with reCAPTCHA v3 and checked on verification. */
    public const RECAPTCHA_ACTION = 'assistant';

    /**
     * The visitor cache.
     *
     * @return \cache
     */
    private static function cache(): \cache {
        return \cache::make('block_openaiagent', 'visitor');
    }

    /**
     * A site setting of the visitor section, as an integer with a floor.
     *
     * @param string $name Setting name.
     * @param int $default Default value.
     * @param int $min Lowest value accepted.
     * @return int
     */
    public static function setting(string $name, int $default, int $min = 0): int {
        $value = get_config('block_openaiagent', $name);
        return max($min, $value === false || $value === '' ? $default : (int)$value);
    }

    /**
     * The site secret that signs page tokens and keys address hashes.
     *
     * Generated on first use and kept in the plugin settings, never sent anywhere.
     *
     * @return string
     */
    private static function secret(): string {
        $secret = (string)get_config('block_openaiagent', 'visitor_secret');
        if (strlen($secret) < 32) {
            $secret = random_string(64);
            set_config('visitor_secret', $secret, 'block_openaiagent');
        }
        return $secret;
    }

    /**
     * A keyed hash of the visitor's address: stable enough to count, useless to anyone else.
     *
     * @return string
     */
    public static function address_hash(): string {
        return substr(hash_hmac('sha256', (string)getremoteaddr(), self::secret()), 0, 32);
    }

    /**
     * Issue a page token for a block.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int|null $now Current time (tests).
     * @return string
     */
    public static function issue_page_token(int $blockinstanceid, ?int $now = null): string {
        $expiry = ($now ?? time()) + self::PAGE_TOKEN_LIFETIME;
        $payload = $blockinstanceid . '.' . $expiry;
        return $payload . '.' . hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * Whether a page token is genuine, unexpired and issued for this block.
     *
     * @param string $token Token from the browser.
     * @param int $blockinstanceid Block the request names.
     * @return bool
     */
    public static function valid_page_token(string $token, int $blockinstanceid): bool {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$blockid, $expiry, $signature] = $parts;
        $expected = hash_hmac('sha256', $blockid . '.' . $expiry, self::secret());
        return hash_equals($expected, $signature)
            && (int)$blockid === $blockinstanceid
            && (int)$expiry >= time();
    }

    /**
     * Whether this address may send another message now.
     *
     * @return bool
     */
    public static function address_allowed(): bool {
        $limit = self::setting('visitor_rate_limit_count', 10);
        if ($limit === 0) {
            return true;
        }
        return (int)(self::cache()->get(self::address_key()) ?: 0) < $limit;
    }

    /**
     * Whether the site-wide daily ceiling has been reached.
     *
     * @return bool
     */
    public static function daily_cap_reached(): bool {
        $cap = self::setting('visitor_daily_cap', 300);
        if ($cap === 0) {
            return false;
        }
        return (int)(self::cache()->get(self::day_key()) ?: 0) >= $cap;
    }

    /**
     * Count one visitor message against the address window and the day.
     *
     * @return void
     */
    public static function record(): void {
        $cache = self::cache();
        foreach ([self::address_key(), self::day_key()] as $key) {
            $cache->set($key, (int)($cache->get($key) ?: 0) + 1);
        }
    }

    /**
     * Messages counted today.
     *
     * @return int
     */
    public static function today_count(): int {
        return (int)(self::cache()->get(self::day_key()) ?: 0);
    }

    /**
     * Cache key of the current address window.
     *
     * @return string
     */
    private static function address_key(): string {
        $window = self::setting('visitor_rate_limit_minutes', 10, 1) * MINSECS;
        return 'ip_' . self::address_hash() . '_' . (int)floor(time() / $window);
    }

    /**
     * Cache key of today's ceiling.
     *
     * @return string
     */
    private static function day_key(): string {
        return 'day_' . (int)floor(time() / DAYSECS);
    }

    /**
     * Tell the site administrators, once a day, that the ceiling was reached.
     *
     * @return bool Whether a notice was sent now.
     */
    public static function notify_cap_reached(): bool {
        $cache = self::cache();
        $key = 'notified_' . (int)floor(time() / DAYSECS);
        if ($cache->get($key)) {
            return false;
        }
        $cache->set($key, 1);

        $cap = self::setting('visitor_daily_cap', 300);
        $settingsurl = new \moodle_url('/admin/settings.php', ['section' => 'blocksettingopenaiagent']);
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'block_openaiagent';
            $message->name = 'visitorcap';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('visitorcap_subject', 'block_openaiagent');
            $message->fullmessage = get_string('visitorcap_body', 'block_openaiagent', (object)[
                'cap' => $cap,
                'url' => $settingsurl->out(false),
            ]);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $settingsurl->out(false);
            $message->contexturlname = get_string('pluginname', 'block_openaiagent');
            message_send($message);
        }
        return true;
    }

    /**
     * The configured captcha, when it is complete enough to use.
     *
     * @return array|null ['provider' => ..., 'sitekey' => ...] for the browser, or null.
     */
    public static function captcha(): ?array {
        $provider = (string)get_config('block_openaiagent', 'visitor_captcha');
        $sitekey = trim((string)get_config('block_openaiagent', 'visitor_captcha_sitekey'));
        $secret = trim((string)get_config('block_openaiagent', 'visitor_captcha_secret'));
        if (!in_array($provider, [self::CAPTCHA_TURNSTILE, self::CAPTCHA_RECAPTCHA], true) || $sitekey === '' || $secret === '') {
            return null;
        }
        return ['provider' => $provider, 'sitekey' => $sitekey, 'action' => self::RECAPTCHA_ACTION];
    }

    /**
     * Verify a captcha token with its provider.
     *
     * Fails closed: if the provider cannot be reached, the message is refused.
     * An anonymous endpoint that answers whenever verification is down is an
     * endpoint without verification.
     *
     * @param string $token Token produced in the browser.
     * @return bool True when no captcha is configured or the token is accepted.
     */
    public static function captcha_passes(string $token): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $captcha = self::captcha();
        if ($captcha === null) {
            return true;
        }
        if (trim($token) === '') {
            return false;
        }

        $url = $captcha['provider'] === self::CAPTCHA_TURNSTILE
            ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            : 'https://www.google.com/recaptcha/api/siteverify';
        $curl = new \curl(['ignoresecurity' => false]);
        $raw = $curl->post($url, [
            'secret' => trim((string)get_config('block_openaiagent', 'visitor_captcha_secret')),
            'response' => $token,
            'remoteip' => (string)getremoteaddr(),
        ], ['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);
        $result = json_decode((string)$raw, true);
        if ($curl->get_errno() || !is_array($result) || empty($result['success'])) {
            return false;
        }
        if ($captcha['provider'] === self::CAPTCHA_RECAPTCHA) {
            $threshold = (float)(get_config('block_openaiagent', 'visitor_captcha_threshold') ?: 0.5);
            return ($result['action'] ?? '') === self::RECAPTCHA_ACTION && (float)($result['score'] ?? 0) >= $threshold;
        }
        return true;
    }

    /**
     * Hand out a conversation token for a stored visitor conversation.
     *
     * @param int $conversationid Conversation id.
     * @param string $existing Token the browser already holds, reused when it is theirs.
     * @return string
     */
    public static function conversation_token(int $conversationid, string $existing = ''): string {
        $token = self::conversation_id($existing) === $conversationid ? $existing : random_string(40);
        self::cache()->set('conv_' . sha1($token), $conversationid);
        return $token;
    }

    /**
     * The conversation a token belongs to, or null.
     *
     * @param string $token Token from the browser.
     * @return int|null
     */
    public static function conversation_id(string $token): ?int {
        if (strlen($token) !== 40 || !ctype_alnum($token)) {
            return null;
        }
        $id = self::cache()->get('conv_' . sha1($token));
        return $id ? (int)$id : null;
    }
}
