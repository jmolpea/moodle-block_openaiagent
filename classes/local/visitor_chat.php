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
 * One visitor turn, from the anonymous endpoint to the orchestrator.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\ai\client_base;
use block_openaiagent\mcp\platform\tools\get_site_access_info;
use block_openaiagent\orchestrator;

/**
 * Runs a message from a visitor who is not logged in.
 *
 * Every check runs on the server, in this order, before anything is spent:
 * the assistant is open to visitors, the page token is genuine, the message is
 * short enough, the address is within its window, the captcha passes, and the
 * site has not reached its daily ceiling. Only then is the message counted and
 * handed to the orchestrator, as user 0, in the block's visitor scope.
 */
final class visitor_chat {
    /**
     * Handle one visitor message.
     *
     * @param int $blockinstanceid Block instance id.
     * @param string $message Message text.
     * @param string $conversationtoken Token of the visitor's conversation ('' for a new one).
     * @param int $pagecourseid Course of the page, for a category block (0 = none).
     * @param string $pagetoken Token issued when the block was drawn.
     * @param string $captchatoken Captcha token ('' when none is configured).
     * @param client_base|null $client Provider client (tests).
     * @return array {success, reply, errorcode, conversationtoken}
     */
    public static function handle(
        int $blockinstanceid,
        string $message,
        string $conversationtoken,
        int $pagecourseid,
        string $pagetoken,
        string $captchatoken,
        ?client_base $client = null
    ): array {
        try {
            $scope = scope::for_block($blockinstanceid, 0, $pagecourseid);
        } catch (\dml_missing_record_exception $e) {
            return self::refuse('error_visitor_unavailable');
        }

        if (!self::available($scope)) {
            return self::refuse('error_visitor_unavailable');
        }
        if (!visitor_guard::valid_page_token($pagetoken, $blockinstanceid)) {
            return self::refuse('error_visitor_session');
        }
        $maxchars = visitor_guard::setting('visitor_max_chars', 500, 50);
        if (\core_text::strlen(trim($message)) > $maxchars) {
            return self::refuse('error_messagetoolong');
        }
        if (!visitor_guard::address_allowed()) {
            return self::refuse('error_ratelimited');
        }
        if (!visitor_guard::captcha_passes($captchatoken)) {
            return self::refuse('error_visitor_captcha');
        }
        if (visitor_guard::daily_cap_reached()) {
            visitor_guard::notify_cap_reached();
            return [
                'success' => true,
                'reply' => self::cap_reply($scope),
                'errorcode' => '',
                'conversationtoken' => $conversationtoken,
            ];
        }

        visitor_guard::record();
        $conversationid = $conversationtoken !== '' ? visitor_guard::conversation_id($conversationtoken) : null;

        $result = (new orchestrator($client))->handle_message(
            (int)SITEID,
            0,
            $message,
            $conversationid,
            $blockinstanceid,
            $scope
        );

        $token = !empty($result['conversationid'])
            ? visitor_guard::conversation_token((int)$result['conversationid'], $conversationtoken)
            : $conversationtoken;

        return [
            'success' => (bool)$result['success'],
            'reply' => link_guard::clean((string)$result['reply'], $blockinstanceid),
            'errorcode' => (string)$result['errorcode'],
            'conversationtoken' => $token,
        ];
    }

    /**
     * Whether the assistant may answer a visitor at all.
     *
     * @param scope $scope Visitor scope.
     * @return bool
     */
    public static function available(scope $scope): bool {
        return block_settings::open_to_visitors($scope)
            && (int)get_config('block_openaiagent', 'enabled') === 1
            && !empty(get_config('block_openaiagent', 'apikey'))
            && \block_openaiagent\license\validator::is_valid()
            && course_config::is_enabled($scope->courseid, $scope->blockinstanceid)
            && ($scope->pagecourseid <= 0 || course_config::core_ai_allows($scope->pagecourseid));
    }

    /**
     * The fixed answer once the daily ceiling is reached: no provider call.
     *
     * @param scope $scope Visitor scope.
     * @return string Markdown.
     */
    private static function cap_reply(scope $scope): string {
        $access = (new get_site_access_info())->execute([], $scope);
        $lines = [get_string('visitor_cap_reply', 'block_openaiagent')];
        $lines[] = '- [' . get_string('login') . '](' . $access['login_url'] . ')';
        if (!empty($access['signup_url'])) {
            $lines[] = '- [' . get_string('startsignup') . '](' . $access['signup_url'] . ')';
        }
        if (!empty($access['support_url'])) {
            $lines[] = '- [' . get_string('contactsitesupport', 'admin') . '](' . $access['support_url'] . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * A refusal the browser can show.
     *
     * @param string $errorcode Language string key.
     * @return array
     */
    private static function refuse(string $errorcode): array {
        return [
            'success' => false,
            'reply' => get_string($errorcode, 'block_openaiagent'),
            'errorcode' => $errorcode,
            'conversationtoken' => '',
        ];
    }
}
