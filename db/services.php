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
 * External services definition for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_openaiagent_send_message' => [
        'classname' => 'block_openaiagent\external\send_message',
        'methodname' => 'execute',
        'description' => 'Send a chat message and receive the assistant reply',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:use',
    ],
    'block_openaiagent_get_conversation' => [
        'classname' => 'block_openaiagent\external\get_conversation',
        'methodname' => 'execute',
        'description' => 'Load the current user conversation for a course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:use',
    ],
    'block_openaiagent_confirm_support_request' => [
        'classname' => 'block_openaiagent\external\confirm_support_request',
        'methodname' => 'execute',
        'description' => 'Confirm or discard a support escalation draft',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:requestsupport',
    ],
    'block_openaiagent_reset_conversation' => [
        'classname' => 'block_openaiagent\external\reset_conversation',
        'methodname' => 'execute',
        'description' => 'Reset the current user conversation for a course',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:use',
    ],
    'block_openaiagent_get_course_config' => [
        'classname' => 'block_openaiagent\external\get_course_config',
        'methodname' => 'execute',
        'description' => 'Read the per-course assistant configuration',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:managecourseconfig',
    ],
    'block_openaiagent_save_course_config' => [
        'classname' => 'block_openaiagent\external\save_course_config',
        'methodname' => 'execute',
        'description' => 'Save the per-course assistant configuration',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:managecourseconfig',
    ],
    'block_openaiagent_test_openai_connection' => [
        'classname' => 'block_openaiagent\external\test_openai_connection',
        'methodname' => 'execute',
        'description' => 'Test connectivity to the OpenAI Responses API',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/openaiagent:manageglobalconfig',
    ],
];
