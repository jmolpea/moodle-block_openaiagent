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
 * External function: a visitor's message to a category or site assistant.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use block_openaiagent\local\markdown;
use block_openaiagent\local\visitor_chat;

/**
 * The plugin's only function reachable without logging in.
 *
 * It is served through Moodle's no-login AJAX endpoint, which carries no
 * session and no sesskey. validate_context() is not called because it ends in
 * require_login(); every check a session would give is done by visitor_chat
 * and visitor_guard instead, and nothing about the caller is taken from the
 * request except the message itself.
 */
class send_public_message extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockid' => new external_value(PARAM_INT, 'Assistant block instance id'),
            'message' => new external_value(PARAM_RAW, 'Visitor message'),
            'conversationtoken' => new external_value(
                PARAM_ALPHANUM,
                'Conversation token, empty for a new one',
                VALUE_DEFAULT,
                ''
            ),
            'pagecourseid' => new external_value(
                PARAM_INT,
                'Course of the page (0 = none); validated on the server',
                VALUE_DEFAULT,
                0
            ),
            'pagetoken' => new external_value(PARAM_RAW_TRIMMED, 'Token issued when the block was drawn'),
            'captchatoken' => new external_value(
                PARAM_RAW_TRIMMED,
                'Invisible captcha token, if the site uses one',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Handle one visitor message.
     *
     * @param int $blockid Block instance id.
     * @param string $message Message.
     * @param string $conversationtoken Conversation token.
     * @param int $pagecourseid Page course.
     * @param string $pagetoken Page token.
     * @param string $captchatoken Captcha token.
     * @return array
     */
    public static function execute(
        int $blockid,
        string $message,
        string $conversationtoken,
        int $pagecourseid,
        string $pagetoken,
        string $captchatoken = ''
    ): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'blockid' => $blockid,
            'message' => $message,
            'conversationtoken' => $conversationtoken,
            'pagecourseid' => $pagecourseid,
            'pagetoken' => $pagetoken,
            'captchatoken' => $captchatoken,
        ]);

        $context = \context_block::instance($params['blockid'], IGNORE_MISSING) ?: \context_system::instance();
        $PAGE->set_context($context);

        $result = visitor_chat::handle(
            $params['blockid'],
            $params['message'],
            $params['conversationtoken'],
            $params['pagecourseid'],
            $params['pagetoken'],
            $params['captchatoken']
        );

        return [
            'success' => $result['success'],
            'reply' => markdown::to_html($result['reply'], $context),
            'errorcode' => $result['errorcode'],
            'conversationtoken' => $result['conversationtoken'],
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the turn succeeded'),
            'reply' => new external_value(PARAM_RAW, 'Assistant reply (sanitized HTML)'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code, empty on success'),
            'conversationtoken' => new external_value(PARAM_ALPHANUM, 'Token to continue this conversation'),
        ]);
    }
}
