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
 * External function: load the current user's conversation in a course.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\course_config;
use block_openaiagent\local\support_action;
use block_openaiagent\local\markdown;

/**
 * Returns the messages of a conversation owned by the current user.
 */
class get_conversation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation id (0 = latest)', VALUE_DEFAULT, 0),
            'blockid' => new external_value(PARAM_INT, 'Owning block instance id (0 = course-wide default)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Load conversation messages.
     *
     * @param int $courseid Course id.
     * @param int $conversationid Conversation id (0 = latest).
     * @param int $blockid Owning block instance id (0 = course-wide default).
     * @return array
     */
    public static function execute(int $courseid, int $conversationid = 0, int $blockid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'conversationid' => $conversationid,
            'blockid' => $blockid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/openaiagent:use', $context);

        $userid = (int)$USER->id;
        $blockinstanceid = (int)$params['blockid'];
        if ($params['conversationid'] > 0) {
            $conversation = conversation_repository::get_owned(
                $params['conversationid'],
                $userid,
                $params['courseid'],
                $blockinstanceid
            );
        } else {
            $conversation = conversation_repository::get_latest($userid, $params['courseid'], $blockinstanceid);
        }

        if ($conversation === null) {
            return ['conversationid' => 0, 'messages' => [], 'actions' => []];
        }

        $rows = conversation_repository::get_messages(
            (int)$conversation->id,
            $userid,
            $params['courseid'],
            0,
            $blockinstanceid
        );
        $messages = [];
        foreach ($rows as $row) {
            // Internal bookkeeping rows: the token-usage records of the router
            // and the rewriter, and the tool-failure markers the eligibility gate
            // reads. Neither is a message and both would render as empty bubbles.
            if ($row->role === 'system' || $row->role === 'tool') {
                continue;
            }
            // Profiles that do not store message text leave the content empty,
            // and an empty bubble is not a message.
            if (trim((string)$row->content) === '') {
                continue;
            }
            // Assistant messages are stored as raw Markdown and rendered to
            // sanitized HTML for display; user messages stay plain text and the
            // client renders them as text.
            $content = (string)$row->content;
            if ($row->role === 'assistant') {
                $content = markdown::to_html($content, $context);
            }
            $messages[] = [
                'role' => (string)$row->role,
                'content' => $content,
                'route' => (string)$row->route,
                'timecreated' => (int)$row->timecreated,
            ];
        }

        // A participant who closed the chat without answering finds the card
        // still waiting when they come back, instead of a conversation that ends
        // in a question nobody can answer any more.
        $config = course_config::resolve($params['courseid'], $blockinstanceid);

        return [
            'conversationid' => (int)$conversation->id,
            'messages' => $messages,
            'actions' => support_action::pending((int)$conversation->id, $config),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'role' => new external_value(PARAM_ALPHA, 'Message role'),
                    'content' => new external_value(
                        PARAM_RAW,
                        'Message content (sanitized HTML for assistant messages, plain text for user messages)'
                    ),
                    'route' => new external_value(PARAM_ALPHANUMEXT, 'Route for assistant messages'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation time'),
                ])
            ),
            'actions' => new external_multiple_structure(
                support_action::external_structure(),
                'Interactive actions still pending an answer',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
