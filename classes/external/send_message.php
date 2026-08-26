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
 * External function: send a chat message to the orchestrator.
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
use block_openaiagent\local\support_action;
use block_openaiagent\orchestrator;

/**
 * Receives a user message and returns the assistant reply.
 */
class send_message extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'message' => new external_value(PARAM_RAW, 'User message'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation to continue (0 for new)', VALUE_DEFAULT, 0),
            'blockid' => new external_value(PARAM_INT, 'Owning block instance id (0 = course-wide default)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Process a message.
     *
     * The authenticated user is taken from the server session, never the
     * request body.
     *
     * @param int $courseid Course id.
     * @param string $message User message.
     * @param int $conversationid Conversation to continue.
     * @param int $blockid Owning block instance id (0 = course-wide default).
     * @return array
     */
    public static function execute(int $courseid, string $message, int $conversationid = 0, int $blockid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'message' => $message,
            'conversationid' => $conversationid,
            'blockid' => $blockid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/openaiagent:use', $context);

        $orchestrator = new orchestrator();
        $result = $orchestrator->handle_message(
            $params['courseid'],
            (int)$USER->id,
            $params['message'],
            $params['conversationid'] > 0 ? $params['conversationid'] : null,
            $params['blockid']
        );

        // The reply is stored raw (Markdown) and rendered to sanitized HTML only
        // for display, so the client can show bold, lists and links safely.
        return [
            'success' => (bool)$result['success'],
            'reply' => markdown::to_html((string)$result['reply'], $context),
            'route' => (string)$result['route'],
            'conversationid' => (int)$result['conversationid'],
            'errorcode' => (string)$result['errorcode'],
            'actions' => array_values($result['actions'] ?? []),
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
            'route' => new external_value(PARAM_ALPHA, 'Resolved route (tutor|assistant|ambiguous)'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code, empty on success'),
            // Added, never replacing anything: a client that ignores this key
            // behaves exactly as it did before, and one that meets an action type
            // it does not know skips it instead of breaking.
            'actions' => new \core_external\external_multiple_structure(
                support_action::external_structure(),
                'Interactive actions to offer alongside the reply',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
