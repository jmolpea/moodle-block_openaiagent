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
 * MCP tool registry and dispatcher.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp;

use block_openaiagent\mcp\config\generic;
use block_openaiagent\mcp\config\interpreter;
use context_course;
use context_module;
use moodle_url;
use completion_info;

/**
 * Registry of all MCP tools exposed to the AI agent.
 */
class tool_registry {
    /**
     * Helper: build a JSON schema definition for a tool.
     *
     * Identity is never part of the schema contract: the orchestrator strips
     * user_id/course_id before exposing tools to the model and overwrites them
     * with the authenticated session values at execution time.
     *
     * @param array $properties JSON schema properties map.
     * @param array $required Required property names.
     * @return array JSON schema array.
     */
    private static function schema(array $properties, array $required = []): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * List tools exposed by this MCP endpoint.
     */
    public static function list_tools(): array {
        $tools = [];

        $tools[] = [
            'name' => 'moodle.get_context',
            'description' => 'Return current course context info (course_id, course_fullname, url, language, timezone).',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session or tool call context.',
                ],
            ], []),
        ];

        $tools[] = [
            'name' => 'moodle.get_current_user_basic',
            'description' => 'Return minimal current user info (id, firstname, lang). First name only: '
                . 'no surname, email or any other profile field is available, so never state or ask for one.',
            'annotations' => null,
            'input_schema' => self::schema([
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_course_outline',
            'description' => 'Return the course structure (sections + activities). Lightweight index for guiding '
                . 'the participant. Each section includes "restricted" (true when the whole section/week is '
                . 'gated by an access restriction) and "section_availability_summary" explaining why.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Target user id to compute visibility/availability as that user.',
                ],
            ], []),
        ];

        $tools[] = [
            'name' => 'moodle.get_course_progress',
            'description' => 'Return completion progress (%), plus completed and pending activities for a user in a course.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_user_course_status',
            'description' => 'Return whether the user is enrolled in the course, their roles in the course, and last access.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_user_grades_summary',
            'description' => 'Return a lightweight grades summary (final grade if available) ' .
                'and pending items summary when possible.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.search_course_content',
            'description' => 'Search activities/resources by name within a course. Matches term by term, so '
                . 'loose names work ("la actividad 2.6" finds "2.6 Actividad individual: Decir que NO"). '
                . 'Results are RANKED, not exact: check "name" before confirming a hit. Gated activities are '
                . 'returned with "available_to_user": false and the reason in "availability_summary" -- report '
                . 'those as restricted, never as non-existent. If the search still returns nothing, call '
                . 'moodle.get_course_outline before telling the user the activity does not exist.',
            'annotations' => null,
            'input_schema' => self::schema([
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query text.',
                ],
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Target user id to compute visibility/availability as that user.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional. Max results (default 10).',
                ],
            ], ['query']),
        ];

        $tools[] = [
            'name' => 'moodle.get_activity_details',
            'description' => 'Return title, intro/description, availability summary, and URL for a given activity (cmid).',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid).',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Target user id to compute availability as that user.',
                ],
            ], ['cmid']),
        ];

        $tools[] = [
            'name' => 'moodle.get_activity_access_requirements',
            'description' => 'Return whether an activity is currently available for the user, ' .
                'and a human-readable availability summary.',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid).',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['cmid', 'user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_section_gate_status',
            'description' => 'Return whether a whole section/week is locked for the user and why, plus which '
                . 'individual activities are locked and why. Reports "section_locked" and '
                . '"section_availability_summary" for section-level access restrictions (e.g. "not available '
                . 'until Week 2 is complete"), and "locked_items" for per-activity restrictions. '
                . 'Identify the section by "section_name" (e.g. "Semana 3" / "Week 3") whenever possible; the '
                . 'server resolves it to the right section. The section number is NOT the week number, so do '
                . 'not guess it.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'section_name' => [
                    'type' => 'string',
                    'description' => 'Section/week name as the user refers to it (e.g. "Semana 3", "Week 3"). '
                        . 'Preferred: the server matches it against the real section titles. Provide this '
                        . 'instead of guessing section_number.',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Internal Moodle section index (0 = top/general). Only use it if you got the '
                        . 'exact number from get_course_outline; otherwise pass section_name instead.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id (injected by the server).',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.list_activity_contents',
            'description' => 'List content items inside an activity (files/pages/chapters). Use content_id with get_content_item.',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid).',
                ],
            ], ['cmid']),
        ];

        $tools[] = [
            'name' => 'moodle.get_content_item',
            'description' => 'Fetch a content item by content_id (text extraction). ' .
                'Supports HTML pages and files; PDFs best-effort.',
            'annotations' => null,
            'input_schema' => self::schema([
                'content_id' => [
                    'type' => 'string',
                    'description' => 'Content item id returned by list_activity_contents.',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Optional. Max characters to return (default 12000).',
                ],
            ], ['content_id']),
        ];

        // Support escalation. Neither of these is part of
        // defaults::default_tool_names(), so they never get a per-course
        // checkbox and never enter $config['tools']: the orchestrator appends
        // them by name, and only for the platform assistant route. That is what
        // keeps them off the tutor, which receives no tools at all.
        $tools[] = [
            'name' => 'moodle.support_request_draft',
            'description' => 'Prepare a support request for the participant to confirm. This does NOT send anything: '
                . 'it returns a reference and the chat then shows the participant a confirmation card. '
                . 'Only call it when you genuinely cannot resolve the question, or when the participant asks to '
                . 'reach a person. You supply the summary and the category and nothing else: the destination '
                . 'address, the participant identity and the delivery are decided by Moodle.',
            'annotations' => null,
            'input_schema' => self::schema([
                'summary' => [
                    'type' => 'string',
                    'description' => 'Plain-text summary of the participant\'s problem, in their language, '
                        . 'written so a support agent who has not read the conversation can act on it. '
                        . 'Do not include any personal data: Moodle adds the participant identity itself.',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => \block_openaiagent\local\defaults::SUPPORT_CATEGORIES,
                    'description' => 'Best matching category for the request.',
                ],
            ], ['summary']),
        ];

        $tools[] = [
            'name' => 'moodle.support_request_status',
            'description' => 'Return the status of the support requests this participant has already sent in this '
                . 'course (reference, category, status and dates). Use it whenever they ask whether their query '
                . 'was sent, instead of answering from memory. Read-only.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from the session.',
                ],
            ], []),
        ];

        // Existing support link tool.
        $tools[] = [
            'name' => 'moodle.get_support_link',
            'description' => 'Return the technical support form link.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from the session.',
                ],
            ], []),
        ];

        $tools[] = [
            'name' => 'moodle.get_user_groups',
            'description' => 'Return the user\'s groups in a course (group ids and names). ' .
                'Useful to explain group-based visibility.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_calendar_events',
            'description' => 'Return calendar events for a course within a time window (default: now to now+30d).',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'time_from' => [
                    'type' => 'integer',
                    'description' => 'Optional. Unix timestamp start (default: now).',
                ],
                'time_to' => [
                    'type' => 'integer',
                    'description' => 'Optional. Unix timestamp end (default: now + 30 days).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional. Max results (default 20).',
                ],
            ], []),
        ];

        $tools[] = [
            'name' => 'moodle.get_gradebook_items',
            'description' => 'Return gradebook items for a course (including weights when available) ' .
                'and the user\'s current grades per item.',
            'annotations' => null,
            'input_schema' => self::schema([
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Course id. If omitted, inferred from MCP session.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
                'include_hidden' => [
                    'type' => 'boolean',
                    'description' => 'Optional. Include hidden grade items (default false).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional. Max grade items (default 200).',
                ],
            ], ['user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_assign_submission_status',
            'description' => 'Return assignment submission status and key settings (due date, cutoff, attempts).',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid) for an assignment.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['cmid', 'user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_quiz_attempts',
            'description' => 'Return quiz settings (attempts, time limit, open/close) and the user\'s attempts list.',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid) for a quiz.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional. Max attempts to return (default 20).',
                ],
            ], ['cmid', 'user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_forum_participation',
            'description' => 'Return forum participation summary for a user (posts, discussions) and subscription mode/status.',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid) for a forum.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id. Pass {{state.user_id}} from the workflow state variables.',
                ],
            ], ['cmid', 'user_id']),
        ];

        $tools[] = [
            'name' => 'moodle.get_activity_configuration',
            'description' => 'Explain WHY an activity behaves as it does for this user: why they cannot see '
                . 'other people\'s posts, why results are not shown yet, why they cannot submit or post. '
                . 'Returns the activity\'s own behaviour rules in plain language (forum type, group mode, '
                . 'completion conditions, posting and closing dates) plus the user\'s state against them. '
                . 'Use it for any "I cannot see / it will not let me / it does not appear" question about an '
                . 'activity. Access restrictions are a different thing: see get_activity_access_requirements.',
            'annotations' => null,
            'input_schema' => self::schema([
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid).',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id (injected by the server).',
                ],
            ], ['cmid']),
        ];

        // Back-compat: also include inputSchema mirrors for clients that prefer it.
        foreach ($tools as &$t) {
            if (!isset($t['inputSchema']) && isset($t['input_schema'])) {
                $t['inputSchema'] = $t['input_schema'];
            }
        }

        return $tools;
    }

    /**
     * Call a tool, fire an audit event, and return the result.
     *
     * The audit event is fired in a finally block with its own try/catch so
     * that a logging failure can never interrupt the tool call result.
     *
     * @param string $tool Tool name.
     * @param array $input Tool input arguments.
     * @param int $calleruserid Authenticated session user id.
     * @param int $courseid Bound course id.
     * @param array $context Orchestration context (conversationid, blockinstanceid).
     * @return array Tool output.
     */
    public static function call(
        string $tool,
        array $input,
        int $calleruserid,
        int $courseid,
        array $context = []
    ): array {
        $ok = false;
        $errtype = '';
        try {
            $result = self::dispatch($tool, $input, $calleruserid, $courseid, $context);
            $ok = true;
            return $result;
        } catch (\Throwable $e) {
            $errtype = get_class($e);
            throw $e;
        } finally {
            try {
                \block_openaiagent\event\mcp_tool_called::make($tool, $calleruserid, $courseid, $ok, $errtype)->trigger();
            } catch (\Throwable $ignored) {
                // Audit failures must never break tool calls.
                unset($ignored);
            }
        }
    }

    /**
     * Internal dispatcher — routes tool name to implementation.
     *
     * @param string $tool Tool name.
     * @param array $input Tool input arguments.
     * @param int $calleruserid Bound user id.
     * @param int $courseid Bound course id.
     * @param array $context Orchestration context (conversationid, blockinstanceid).
     * @return array Tool output.
     */
    private static function dispatch(
        string $tool,
        array $input,
        int $calleruserid,
        int $courseid,
        array $context = []
    ): array {
        switch ($tool) {
            case 'moodle.support_request_draft':
                return self::support_request_draft($input, $calleruserid, $courseid, $context);

            case 'moodle.support_request_status':
                return self::support_request_status($calleruserid, $courseid);

            case 'moodle.get_context':
                return self::get_context($courseid, $calleruserid);

            case 'moodle.get_current_user_basic':
                return self::get_current_user_basic($input, $calleruserid);

            case 'moodle.get_course_outline':
                return self::get_course_outline($input, $courseid, $calleruserid);

            case 'moodle.get_course_progress':
                return self::get_course_progress($input, $courseid, $calleruserid);

            case 'moodle.get_user_course_status':
                return self::get_user_course_status($input, $courseid, $calleruserid);

            case 'moodle.get_user_grades_summary':
                return self::get_user_grades_summary($input, $courseid, $calleruserid);

            case 'moodle.search_course_content':
                return self::search_course_content($input, $courseid, $calleruserid);

            case 'moodle.get_activity_details':
                return self::get_activity_details($input, $courseid, $calleruserid);

            case 'moodle.get_activity_access_requirements':
                return self::get_activity_access_requirements($input, $courseid, $calleruserid);

            case 'moodle.get_section_gate_status':
                return self::get_section_gate_status($input, $courseid, $calleruserid);

            case 'moodle.list_activity_contents':
                return self::list_activity_contents($input, $courseid, $calleruserid);

            case 'moodle.get_content_item':
                return self::get_content_item($input, $courseid, $calleruserid);

            case 'moodle.get_support_link':
                return self::get_support_link($calleruserid, $courseid);

            case 'moodle.get_user_groups':
                return self::get_user_groups($input, $courseid, $calleruserid);

            case 'moodle.get_calendar_events':
                return self::get_calendar_events($input, $courseid, $calleruserid);

            case 'moodle.get_gradebook_items':
                return self::get_gradebook_items($input, $courseid, $calleruserid);

            case 'moodle.get_assign_submission_status':
                return self::get_assign_submission_status($input, $courseid, $calleruserid);

            case 'moodle.get_quiz_attempts':
                return self::get_quiz_attempts($input, $courseid, $calleruserid);

            case 'moodle.get_forum_participation':
                return self::get_forum_participation($input, $courseid, $calleruserid);

            case 'moodle.get_activity_configuration':
                return self::get_activity_configuration($input, $courseid, $calleruserid);

            default:
                throw new \moodle_exception('mcp_unknown_tool', 'block_openaiagent', '', $tool);
        }
    }

    /**
     * Resolve target user id: always the caller, never the input.
     *
     * A tool reads one participant's grades, submissions and forum activity, so
     * getting this wrong is a disclosure of somebody else's academic record.
     * The argument is therefore ignored outright rather than trusted and then
     * validated, because the validation that follows in each tool
     * (require_course_view) asks whether the *target* may see the course, not
     * whether the *caller* may read that target's data - it would pass for a
     * victim who is legitimately enrolled.
     *
     * Nothing changes for the current callers: the orchestrator already strips
     * user_id from the schema the model sees and then overwrites it with the
     * session user, so this method already returned $calleruserid every time.
     * The point is that a future entry point cannot get it wrong by omission.
     *
     * $input is kept in the signature because every tool passes it and a
     * parameter that must be ignored is clearer than a call site that has to
     * remember not to pass it.
     *
     * @param array $input Tool input arguments (the user_id key is ignored).
     * @param int $calleruserid Server-trusted user id.
     * @return int Resolved user id, always $calleruserid.
     */
    private static function resolve_userid(array $input, int $calleruserid): int {
        unset($input);

        return $calleruserid;
    }

    /**
     * Basic course view permission check for target user.
     *
     * Accepts enrolled students (who access via enrolment, not the capability)
     * AND users who have moodle/course:view explicitly (teachers, managers, admins).
     *
     * @param int $courseid Course id.
     * @param int $userid User id to check.
     * @return void
     */
    private static function require_course_view(int $courseid, int $userid): void {
        $coursecontext = context_course::instance($courseid);
        if (
            !is_enrolled($coursecontext, $userid, '', true) &&
                !has_capability('moodle/course:view', $coursecontext, $userid)
        ) {
            throw new \required_capability_exception($coursecontext, 'moodle/course:view', 'nopermissions', '');
        }
    }

    /**
     * Ensure a course module belongs to the session-bound course.
     *
     * The tools that take a cmid derive their working course from the module
     * itself. Without this check a caller could pass a cmid from any other
     * course they happen to be enrolled in and read its activity data through
     * the assistant, even though the chat is scoped to a single course. Binding
     * the cmid to the token/session course closes that cross-course path.
     *
     * @param int $cmid Course module id supplied by the model.
     * @param int $boundcourseid The session-bound course id.
     * @param int $modulecourseid The course the module actually lives in.
     * @return void
     * @throws \moodle_exception When the module is outside the bound course.
     */
    private static function require_module_in_course(int $cmid, int $boundcourseid, int $modulecourseid): void {
        if ($boundcourseid > 0 && $modulecourseid !== $boundcourseid) {
            $debug = 'cmid ' . $cmid . ' is not part of course ' . $boundcourseid;
            throw new \moodle_exception('mcp_course_mismatch', 'block_openaiagent', '', null, $debug);
        }
    }

    /**
     * Refuse a module the participant is not allowed to see at all.
     *
     * The listing tools (search_course_content, get_course_outline,
     * get_section_gate_status) already skip teacher-hidden activities with this
     * exact test, and a unit test pins the behaviour. The tools addressed by a
     * raw cmid did not apply it, so a cmid the model produced from anywhere --
     * a guess, a stale reference, an earlier conversation -- could still return
     * a hidden activity's name, its settings, its content listing and, through
     * get_content_item, the full text of a hidden page or file.
     *
     * BOTH conditions matter. An activity that is restricted but shown greyed
     * out keeps visible = 1 while uservisible is false, and explaining exactly
     * that is the assistant's job, so it must pass. Only a teacher-hidden
     * activity has both false. A teacher keeps uservisible = true through
     * viewhiddenactivities, so this never blocks staff.
     *
     * @param int $cmid Course module id.
     * @param int $courseid Course the module belongs to.
     * @param int $userid Target user id.
     * @return \cm_info The module info, built for that user.
     * @throws \moodle_exception When the module is hidden from the user.
     */
    private static function require_module_visible(int $cmid, int $courseid, int $userid): \cm_info {
        $modinfo = get_fast_modinfo(get_course($courseid), $userid);
        $cminfo = $modinfo->get_cm($cmid);

        if (!$cminfo->uservisible && !$cminfo->visible) {
            throw new \moodle_exception('mcp_activity_not_visible', 'block_openaiagent');
        }

        return $cminfo;
    }

    /**
     * Render a section/activity availability reason to plain text.
     *
     * The $availableinfo of a section or course module is NOT always a string:
     * when the item carries a SINGLE access condition Moodle stores a plain HTML
     * string, but with MULTIPLE conditions (e.g. "grade in X and grade in Y and
     * a date") it stores a core_availability_multiple_messages object, which
     * cannot be cast to string and throws "Object of class ... could not be
     * converted to string". The canonical converter is
     * \core_availability\info::format_info(), which handles both shapes and
     * returns HTML; it is then flattened to plain text for the model. Any failure
     * degrades to an empty string so a single unrenderable item never breaks the
     * whole tool call.
     *
     * @param mixed $availableinfo Raw availableinfo (string, renderable object, or null).
     * @param int $courseid Course id the item belongs to.
     * @return string Plain-text reason, or '' when there is none / it cannot be rendered.
     */
    private static function render_availability_info($availableinfo, int $courseid): string {
        if ($availableinfo === null || $availableinfo === '') {
            return '';
        }
        try {
            if (is_object($availableinfo)) {
                $html = \core_availability\info::format_info($availableinfo, $courseid);
            } else {
                $html = (string)$availableinfo;
            }
            return trim(html_to_text($html));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Tool: current context info.
     *
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Context info array.
     */
    private static function get_context(int $courseid, int $calleruserid): array {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,lang', MUST_EXIST);
        $url = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        $lang = $course->lang ?: current_language();
        $timezone = \core_date::get_user_timezone($calleruserid);

        return [
            'course_id' => (int)$course->id,
            'course_fullname' => (string)$course->fullname,
            'course_shortname' => (string)$course->shortname,
            'url' => $url,
            'language' => $lang,
            'timezone' => $timezone,
            // The chat is course-scoped, not activity-scoped: no cmid/section.
            'cmid' => null,
            'section' => null,
        ];
    }

    /**
     * Tool: minimal current user info.
     *
     * @param array $input Tool input arguments.
     * @param int $calleruserid Bound user id.
     * @return array User info array.
     */
    private static function get_current_user_basic(array $input, int $calleruserid): array {
        global $DB;
        $userid = self::resolve_userid($input, $calleruserid);
        // First name only, deliberately. Whatever a tool returns is fed straight
        // back into the model's context and leaves the site, so this returns the
        // minimum that lets an agent address the participant. The surname was
        // here and served no purpose beyond that, while making the person
        // identifiable outside Moodle. Do not add email, username or idnumber.
        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lang', MUST_EXIST);

        return [
            'moodle_user_id' => (int)$user->id,
            'firstname' => (string)$user->firstname,
            'lang' => (string)$user->lang,
        ];
    }

    /**
     * Tool: course outline (sections + activities).
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Course outline array.
     */
    private static function get_course_outline(array $input, int $courseid, int $calleruserid): array {
        global $DB;

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course, $targetuserid);

        $coursecontext = context_course::instance($courseid);

        $sectionsout = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            // Skip sections the teacher has hidden outright (no access rule involved).
            if (!$section->visible) {
                continue;
            }

            // A section can be gated by an access restriction (e.g. "complete Week 2").
            // The correct signal is $section->available (false = the user has NOT met
            // the access conditions), NOT $section->uservisible: a restriction shown
            // "greyed out" keeps the section VISIBLE (uservisible = true) while
            // available is false, which is exactly the disabled/greyed week-tab case.
            // Only when the teacher chooses "hide entirely" does uservisible become
            // false too. $section->availableinfo carries the human-readable reason
            // (populated in the greyed-out mode). Building the summary touches the
            // availability API and text filters, which can fail on some sections; a
            // single bad section must never fail the whole outline, so every field is
            // computed defensively and the section is always listed with at least its
            // number, name and restricted flag so the assistant can follow up on it.
            $sectionavailable = true;
            try {
                $sectionavailable = (bool)$section->available;
            } catch (\Throwable $e) {
                $sectionavailable = (bool)$section->uservisible;
            }
            $sectionrestricted = !$sectionavailable;

            $sectionavailability = self::render_availability_info($section->availableinfo, $courseid);

            // The section summary is only a lightweight hint here; some summaries are
            // very long (full syllabus, webinar schedules) and, multiplied across
            // sections, can blow past the tool-result size budget and push the later
            // (often restricted) sections out of the model's view. Cap it hard.
            $sectionsummary = '';
            try {
                if (!empty($section->summary)) {
                    $sectionsummary = trim(html_to_text(format_text(
                        $section->summary,
                        $section->summaryformat,
                        ['context' => $coursecontext]
                    )));
                    if (\core_text::strlen($sectionsummary) > 300) {
                        $sectionsummary = \core_text::substr($sectionsummary, 0, 300) . '…';
                    }
                }
            } catch (\Throwable $e) {
                $sectionsummary = '';
            }

            // Enumerate activities whenever the user can see the section at all
            // (uservisible), including a greyed-out restricted section. Only a section
            // hidden entirely from the user exposes no per-activity data.
            $modulesout = [];
            if ($section->uservisible) {
                $cmids = $modinfo->get_sections()[$section->section] ?? [];
                foreach ($cmids as $cmid) {
                    try {
                        $cm = $modinfo->get_cm($cmid);
                        if (!$cm->uservisible && !$cm->visible) {
                            continue;
                        }
                        $availability = self::render_availability_info($cm->availableinfo, $courseid);
                        $modulesout[] = [
                            'cmid' => (int)$cm->id,
                            'modname' => (string)$cm->modname,
                            'name' => format_string($cm->name, true, ['context' => $coursecontext]),
                            'url' => $cm->url ? (string)$cm->url : '',
                            'visible' => (bool)$cm->visible,
                            'available_to_user' => (bool)$cm->uservisible,
                            'availability_summary' => $availability,
                        ];
                    } catch (\Throwable $e) {
                        // Skip an activity that cannot be read rather than failing all.
                        continue;
                    }
                }
            }

            $sectionsout[] = [
                'section_number' => (int)$section->section,
                'name' => format_string($section->name ?? '', true, ['context' => $coursecontext]),
                'summary' => $sectionsummary,
                'restricted' => $sectionrestricted,
                'section_availability_summary' => $sectionavailability,
                'modules' => $modulesout,
            ];
        }

        return [
            'course_id' => (int)$courseid,
            'course_fullname' => (string)$course->fullname,
            'sections' => $sectionsout,
        ];
    }

    /**
     * Tool: course progress and completed/pending activities.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Progress data array.
     */
    private static function get_course_progress(array $input, int $courseid, int $calleruserid): array {
        global $CFG;

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        // Completion info and state constants live in completionlib.php.
        require_once($CFG->libdir . '/completionlib.php');

        $course = get_course($courseid);
        $completion = new completion_info($course);

        if (!$completion->is_enabled()) {
            return [
                'completion_enabled' => false,
                'percent' => null,
                'completed' => [],
                'pending' => [],
                'message' => 'Completion tracking is not enabled for this course.',
            ];
        }

        // Percentage (best-effort).
        $percent = null;
        if (
            class_exists('core_completion\\progress') &&
                method_exists('core_completion\\progress', 'get_course_progress_percentage')
        ) {
            try {
                $percent = \core_completion\progress::get_course_progress_percentage($course, $targetuserid);
                if ($percent !== null) {
                    $percent = (float)$percent;
                }
            } catch (\Throwable $e) {
                $percent = null;
            }
        }

        $modinfo = get_fast_modinfo($course, $targetuserid);
        $completed = [];
        $pending = [];
        $totaltrackable = 0;
        $completedcount = 0;

        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            // Only consider activities with completion tracking.
            if (empty($cm->completion)) {
                continue;
            }
            $totaltrackable++;

            // Completion data can throw in edge cases; treat those items as pending.
            try {
                $cdata = $completion->get_data($cm, false, $targetuserid);
                $iscomplete = (!empty($cdata) && (int)$cdata->completionstate === COMPLETION_COMPLETE);
            } catch (\Throwable $e) {
                $iscomplete = false;
            }

            if ($iscomplete) {
                $completedcount++;
                $completed[] = [
                    'cmid' => (int)$cm->id,
                    'modname' => (string)$cm->modname,
                    'name' => (string)$cm->name,
                ];
            } else {
                $pending[] = [
                    'cmid' => (int)$cm->id,
                    'modname' => (string)$cm->modname,
                    'name' => (string)$cm->name,
                ];
            }
        }

        // If percent unavailable, compute naive percent from trackable items.
        if ($percent === null && $totaltrackable > 0) {
            $percent = round(($completedcount / $totaltrackable) * 100, 2);
        }

        return [
            'completion_enabled' => true,
            'percent' => $percent,
            'completed_count' => $completedcount,
            'total_count' => $totaltrackable,
            'completed' => $completed,
            'pending' => $pending,
        ];
    }

    /**
     * Tool: user-course status.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array User-course status array.
     */
    private static function get_user_course_status(array $input, int $courseid, int $calleruserid): array {
        global $DB;
        $targetuserid = self::resolve_userid($input, $calleruserid);

        $coursecontext = context_course::instance($courseid);

        $enrolled = is_enrolled($coursecontext, $targetuserid, '', true);
        $roles = [];
        $assignments = get_user_roles($coursecontext, $targetuserid, true);
        foreach ($assignments as $ra) {
            $roles[] = [
                'roleid' => (int)$ra->roleid,
                'shortname' => (string)$ra->shortname,
                'name' => (string)role_get_name($ra, $coursecontext),
            ];
        }

        $lastaccess = $DB->get_field('user_lastaccess', 'timeaccess', ['userid' => $targetuserid, 'courseid' => $courseid]);
        return [
            'course_id' => (int)$courseid,
            'user_id' => (int)$targetuserid,
            'is_enrolled' => (bool)$enrolled,
            'roles' => $roles,
            'last_access' => $lastaccess ? userdate((int)$lastaccess) : null,
        ];
    }

    /**
     * Tool: grades summary (best-effort).
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Grades summary array.
     */
    private static function get_user_grades_summary(array $input, int $courseid, int $calleruserid): array {
        global $CFG, $DB;
        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        // Load grade libraries explicitly — not always auto-loaded in headless MCP context.
        require_once($CFG->libdir . '/gradelib.php');
        if (file_exists($CFG->dirroot . '/grade/querylib.php')) {
            require_once($CFG->dirroot . '/grade/querylib.php');
        }

        // Final grade (if gradebook exists).
        $final = null;
        if (function_exists('grade_get_course_grade')) {
            $g = grade_get_course_grade($targetuserid, $courseid);
            if ($g && isset($g->grade) && $g->grade !== null) {
                $final = round((float)$g->grade, 2);
            }
        }

        // Pending items heuristic: count completion-pending items in course (not true "grades", but useful).
        $progress = self::get_course_progress(['user_id' => $targetuserid], $courseid, $calleruserid);
        $pendingcount = is_array($progress['pending'] ?? null) ? count($progress['pending']) : null;

        return [
            'course_id' => (int)$courseid,
            'user_id' => (int)$targetuserid,
            'final_grade' => $final,
            'pending_activities_count' => $pendingcount,
        ];
    }

    /**
     * Tool: search by activity/resource name.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Search results array.
     */
    private static function search_course_content(array $input, int $courseid, int $calleruserid): array {
        $q = trim((string)($input['query'] ?? ''));
        if ($q === '') {
            throw new \moodle_exception('invalidparameter', 'core', '', 'query');
        }
        if (strlen($q) > 200) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'query too long (max 200 chars)');
        }

        $limit = isset($input['limit']) ? (int)$input['limit'] : 10;
        if ($limit <= 0 || $limit > 50) {
            $limit = 10;
        }

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course, $targetuserid);

        $terms = self::search_terms($q);

        $results = [];
        foreach ($modinfo->cms as $cm) {
            // Same visibility rule as get_course_outline: only an activity the
            // teacher hid AND the user cannot see is dropped. Anything gated but
            // announced is returned, because "there is no such activity" is the
            // worst possible answer for something that exists and is merely locked.
            //
            // Do NOT additionally require a per-activity reason here. When the gate
            // is on the SECTION (a whole week locked), the activities inside carry
            // uservisible=false with an EMPTY availableinfo -- the reason lives on
            // the section -- so requiring it hid every activity of a locked week and
            // put this tool back to returning nothing, which is what it was fixed for.
            if (!$cm->uservisible && !$cm->visible) {
                continue;
            }
            $availability = self::render_availability_info($cm->availableinfo, $courseid);
            if ($availability === '' && !$cm->uservisible) {
                // Fall back to the section gate so the caller can still say WHY.
                try {
                    $sectioninfo = $modinfo->get_section_info($cm->sectionnum);
                    $availability = self::render_availability_info($sectioninfo->availableinfo, $courseid);
                } catch (\Throwable $e) {
                    $availability = '';
                }
            }

            $haystack = self::normalize_for_search((string)$cm->name);
            $matched = 0;
            foreach ($terms as $term) {
                if (strpos($haystack, $term) !== false) {
                    $matched++;
                }
            }
            if ($matched === 0) {
                continue;
            }

            $results[] = [
                'cmid' => (int)$cm->id,
                'modname' => (string)$cm->modname,
                'name' => (string)$cm->name,
                'section_number' => (int)$cm->sectionnum,
                'url' => (string)$cm->url,
                'available_to_user' => (bool)$cm->uservisible,
                'availability_summary' => $availability,
                'matched_terms' => $matched,
            ];
        }

        // Best match first: most query terms matched, then the shortest name, which
        // is the more specific hit when several activities share a prefix.
        usort($results, static function (array $a, array $b): int {
            return [$b['matched_terms'], \core_text::strlen($a['name'])]
                <=> [$a['matched_terms'], \core_text::strlen($b['name'])];
        });
        $results = array_slice($results, 0, $limit);

        $notes = [
            'matching' => 'Results are ranked, not exact. "matched_terms" out of "term_count" says how '
                . 'many query terms the name contains, so a result with fewer than term_count matches may '
                . 'be a near miss: check "name" before telling the user this is the activity they meant.',
            'restricted' => 'A result with "available_to_user": false EXISTS but is gated. Report it as '
                . 'restricted, quoting "availability_summary", never as non-existent.',
            'never_equate' => 'NEVER tell the user that the activity they named "is" or "corresponds to" an '
                . 'activity with a different name. If the name they used does not appear here, say you could '
                . 'not find that exact activity. Naming a different activity as if it were theirs is worse '
                . 'than finding nothing.',
        ];
        if (empty($results)) {
            $notes['no_results'] = 'Nothing matched. Do NOT infer the activity from any other tool result, '
                . 'from the conversation, or from what you said in an earlier turn. Call '
                . 'moodle.get_course_outline: the activity may live in a section this user cannot open yet, '
                . 'in which case report it as restricted. If it is still not there, say plainly that you '
                . 'cannot find it and offer the support link.';
        }

        return [
            'query' => $q,
            'search_terms' => $terms,
            'term_count' => count($terms),
            'count' => count($results),
            'results' => $results,
            'notes' => $notes,
        ];
    }

    /**
     * Normalise a name or a query for tolerant matching.
     *
     * Lowercases and collapses every punctuation run to a single space, so
     * "2.3. Webinar: Semana 2" and "2.3 Webinar Semana 2" become the same string.
     * A dot BETWEEN digits is preserved, so section numbering like "2.6" or
     * "2.3.1" survives as one token instead of splitting into "2" and "6".
     *
     * @param string $text Raw text.
     * @return string Normalised text.
     */
    private static function normalize_for_search(string $text): string {
        $text = \core_text::strtolower(trim($text));
        // Park the numbering dots out of reach of the punctuation strip.
        $text = preg_replace('/(?<=\d)\.(?=\d)/u', "\x01", $text);
        $text = preg_replace('/[^\p{L}\p{N}\x01]+/u', ' ', $text);
        $text = str_replace("\x01", '.', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Split a query into the terms worth matching on.
     *
     * Participants name activities loosely -- "la actividad 2.6", "el foro de la
     * semana 2" -- so the whole phrase is almost never a substring of the real
     * name. Matching term by term is what makes those queries resolve. Connectors
     * ("de", "la", "the") are dropped because they match every name and would flatten
     * the ranking; any token carrying a digit is kept however short, since that is
     * usually the most distinctive part ("2.6").
     *
     * @param string $query Raw query.
     * @return string[] Distinct search terms.
     */
    private static function search_terms(string $query): array {
        $tokens = preg_split('/\s+/u', self::normalize_for_search($query)) ?: [];
        $terms = [];
        foreach ($tokens as $token) {
            $len = \core_text::strlen($token);
            if ($len < 2) {
                continue;
            }
            if ($len < 3 && preg_match('/\d/u', $token) !== 1) {
                continue;
            }
            $terms[$token] = true;
        }
        // A query made only of connectors still has to search on something.
        if (empty($terms)) {
            $whole = self::normalize_for_search($query);
            if ($whole !== '') {
                $terms[$whole] = true;
            }
        }
        return array_keys($terms);
    }

    /**
     * Tool: activity details.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Activity details array.
     */
    private static function get_activity_details(array $input, int $boundcourseid, int $calleruserid): array {
        global $DB;

        $cmid = (int)($input['cmid'] ?? 0);
        if (!$cmid) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'cmid');
        }

        // Determine course and target user for visibility/availability.
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $cminfo = self::require_module_visible($cmid, $courseid, $targetuserid);

        $availability = self::render_availability_info($cminfo->availableinfo, $courseid);
        $url = (string)$cminfo->url;

        // Get intro/description from module instance where applicable.
        $introplain = '';
        $introhtml = '';
        if ($DB->get_manager()->table_exists($cminfo->modname)) {
            $instance = $DB->get_record($cminfo->modname, ['id' => $cminfo->instance], '*', IGNORE_MISSING);
            if ($instance && isset($instance->intro)) {
                $context = context_module::instance($cmid);
                $introhtml = format_text($instance->intro, $instance->introformat ?? FORMAT_HTML, ['context' => $context]);
                $introplain = trim(html_to_text($introhtml));
            }
        }

        return [
            'cmid' => (int)$cminfo->id,
            'course_id' => (int)$courseid,
            'modname' => (string)$cminfo->modname,
            'name' => (string)$cminfo->name,
            'url' => $url,
            'intro_plain' => $introplain,
            'availability_summary' => $availability,
        ];
    }

    /**
     * Tool: activity availability summary.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Availability requirements array.
     */
    private static function get_activity_access_requirements(array $input, int $boundcourseid, int $calleruserid): array {
        $cmid = (int)($input['cmid'] ?? 0);
        if (!$cmid) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'cmid');
        }

        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $cminfo = self::require_module_visible($cmid, $courseid, $targetuserid);

        $isavailable = (bool)$cminfo->available;
        $summary = self::render_availability_info($cminfo->availableinfo, $courseid);

        return [
            'cmid' => (int)$cmid,
            'course_id' => (int)$courseid,
            'is_available_now' => $isavailable,
            'availability_summary' => $summary,
        ];
    }

    /**
     * Tool: how the activity itself behaves for this user.
     *
     * The companion to get_activity_access_requirements, and deliberately a
     * separate tool. That one reads core_availability, which only knows about
     * configured access restrictions (dates, groups, grades, completion); an
     * activity with none reports "available" even when its own settings stop the
     * user seeing what they are asking about. A Q&A forum is the canonical case:
     * no restrictions whatsoever, yet other people's posts stay hidden until the
     * user posts and the editing window passes. Both halves are returned here so
     * the model can answer without a second round trip.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Behaviour rules, user state and availability.
     */
    private static function get_activity_configuration(array $input, int $boundcourseid, int $calleruserid): array {
        global $DB;

        $cmid = (int)($input['cmid'] ?? 0);
        if (!$cmid) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'cmid');
        }

        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $cminfo = self::require_module_visible($cmid, $courseid, $targetuserid);

        $instance = null;
        if ($DB->get_manager()->table_exists($cminfo->modname)) {
            $instance = $DB->get_record($cminfo->modname, ['id' => $cminfo->instance], '*', IGNORE_MISSING);
        }
        $instance = $instance ?: new \stdClass();

        $rules = [];
        $state = [];

        // Module-specific rules are collected first: they are the ones that
        // answer the question, and the MAX_RULES cap trims from the end.
        $specific = interpreter::for_modname($cminfo->modname);
        foreach (array_filter([$specific, generic::class]) as $class) {
            try {
                $rules = array_merge($rules, $class::rules($instance, $cminfo, $targetuserid));
                $state = array_merge($state, $class::user_state($instance, $cminfo, $targetuserid));
            } catch (\Throwable $e) {
                // Module internals move between Moodle versions. One interpreter
                // failing must degrade to fewer rules, never to a failed call.
                continue;
            }
        }

        $rules = array_slice(array_values(array_unique($rules)), 0, interpreter::MAX_RULES);

        $result = [
            'cmid' => (int)$cminfo->id,
            'course_id' => $courseid,
            'modname' => (string)$cminfo->modname,
            'name' => (string)$cminfo->name,
            'url' => (string)$cminfo->url,
            'behaviour_rules' => $rules,
            'user_state' => $state,
            'is_available_now' => (bool)$cminfo->available,
            'availability_summary' => self::render_availability_info($cminfo->availableinfo, $courseid),
        ];

        // Silence is the dangerous answer here: an empty rule list is what the
        // old behaviour ("this activity has no restrictions") was built on, and
        // it is what sent the participant looking where there was nothing.
        if (!$rules) {
            $result['note'] = get_string('actcfg_no_rules_note', 'block_openaiagent');
        }

        return $result;
    }

    /**
     * Tool: section gate status (locked activities + why).
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Section gate status array.
     */
    private static function get_section_gate_status(array $input, int $courseid, int $calleruserid): array {
        $sectionname = trim((string)($input['section_name'] ?? ''));
        $hasnumber = isset($input['section_number']) && $input['section_number'] !== '';
        $sectionnum = (int)($input['section_number'] ?? 0);
        if ($hasnumber && $sectionnum < 0) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'section_number');
        }

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course, $targetuserid);
        $coursecontext = context_course::instance($courseid);

        // Resolve the section by name when provided (preferred): the week number the
        // user says is almost never the internal section index, so matching the real
        // section titles avoids the model guessing the wrong number. Falls back to the
        // supplied section_number when no name is given or nothing matches.
        $resolvedbyname = null;
        if ($sectionname !== '') {
            $needle = \core_text::strtolower($sectionname);
            foreach ($modinfo->get_section_info_all() as $s) {
                $title = \core_text::strtolower(trim(format_string($s->name ?? '', true, ['context' => $coursecontext])));
                if ($title === '') {
                    continue;
                }
                if ($title === $needle || strpos($title, $needle) !== false || strpos($needle, $title) !== false) {
                    $resolvedbyname = (int)$s->section;
                    break;
                }
            }
        }
        if ($resolvedbyname !== null) {
            $sectionnum = $resolvedbyname;
        }

        // First, check whether the section itself is gated by an access restriction
        // (e.g. "not available until the Week 2 activities are complete"). This is the
        // most common case for a locked/greyed-out week tab and is stored on the
        // section_info object, not on the individual activities.
        // The gate signal is $section->available (false = access conditions not met),
        // NOT $section->uservisible: a restriction shown "greyed out" keeps the section
        // visible (uservisible = true) while available is false -- exactly the disabled
        // week-tab case the user reported. availableinfo carries the human-readable
        // reason. Both are read defensively so a rendering failure never breaks the tool.
        $section = $modinfo->get_section_info($sectionnum);
        $sectionlocked = false;
        $sectionsummary = '';
        if ($section) {
            try {
                $sectionlocked = !(bool)$section->available;
            } catch (\Throwable $e) {
                $sectionlocked = !(bool)$section->uservisible;
            }
            $sectionsummary = self::render_availability_info($section->availableinfo, $courseid);
        }

        $locked = [];
        // Also list individual activities the user cannot access yet. A restriction can
        // sit on the section, on the activities, or both, so inspect activities even
        // when the section itself is gated. "Not available" is $cm->available === false
        // (again, not uservisible, which stays true for greyed-out items).
        $cmids = $modinfo->get_sections()[$sectionnum] ?? [];
        foreach ($cmids as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
                // Skip items hidden outright by the teacher (not an access rule).
                if (!$cm->visible && !$cm->uservisible) {
                    continue;
                }
                $cmavailable = true;
                try {
                    $cmavailable = (bool)$cm->available;
                } catch (\Throwable $e) {
                    $cmavailable = (bool)$cm->uservisible;
                }
                if ($cmavailable) {
                    continue;
                }
                $summary = self::render_availability_info($cm->availableinfo, $courseid);
                if ($summary === '') {
                    continue;
                }
                $locked[] = [
                    'cmid' => (int)$cm->id,
                    'modname' => (string)$cm->modname,
                    'name' => format_string($cm->name, true, ['context' => $coursecontext]),
                    'availability_summary' => $summary,
                    'url' => $cm->url ? (string)$cm->url : '',
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [
            'course_id' => (int)$courseid,
            'section_number' => (int)$sectionnum,
            'section_name' => $section ? format_string($section->name ?? '', true, ['context' => $coursecontext]) : '',
            'resolved_by_name' => $resolvedbyname !== null,
            'section_locked' => $sectionlocked,
            'section_availability_summary' => $sectionsummary,
            'locked_items_count' => count($locked),
            'locked_items' => $locked,
        ];
    }

    /**
     * Tool: list content items in an activity.
     *
     * Content IDs are stable strings consumed by get_content_item:
     * - page:<cmid>
     * - bookchapter:<chapterid>
     * - file:<fileid>
     * - h5p:<cmid>
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Content items list array.
     */
    private static function list_activity_contents(array $input, int $boundcourseid, int $calleruserid): array {
        global $DB;
        $cmid = (int)($input['cmid'] ?? 0);
        if (!$cmid) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'cmid');
        }

        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);

        self::require_course_view($courseid, $calleruserid);
        self::require_module_visible($cmid, $courseid, $calleruserid);

        $modname = $cm->modname;
        $context = context_module::instance($cmid);

        $items = [];

        if ($modname === 'page') {
            $items[] = [
                'type' => 'html',
                'title' => 'Page content',
                'content_id' => 'page:' . $cmid,
                'mimetype' => 'text/html',
                'size' => null,
            ];
        } else if ($modname === 'book') {
            $chapters = $DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum ASC', 'id,title');
            foreach ($chapters as $ch) {
                $items[] = [
                    'type' => 'html',
                    'title' => (string)$ch->title,
                    'content_id' => 'bookchapter:' . (int)$ch->id,
                    'mimetype' => 'text/html',
                    'size' => null,
                ];
            }
        } else if ($modname === 'resource' || $modname === 'folder') {
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_' . $modname, 'content', 0, 'filename', false);
            foreach ($files as $f) {
                // Queue for background indexing (PDF/text) so chat queries are fast.
                $mt = $f->get_mimetype();
                if ($mt === 'application/pdf' || strpos($mt, 'text/') === 0) {
                    \block_openaiagent\local\filetext_store::ensure_queued($f);
                }
                $items[] = [
                    'type' => 'file',
                    'title' => $f->get_filename(),
                    'content_id' => 'file:' . (int)$f->get_id(),
                    'mimetype' => $f->get_mimetype(),
                    'size' => (int)$f->get_filesize(),
                ];
            }
        } else if ($modname === 'h5pactivity') {
            $items[] = [
                'type' => 'h5p',
                'title' => 'H5P content',
                'content_id' => 'h5p:' . $cmid,
                'mimetype' => 'application/json',
                'size' => null,
            ];
        } else {
            // Fallback: try file area 'content' if it exists.
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_' . $modname, 'content', 0, 'filename', false);
            foreach ($files as $f) {
                // Queue for background indexing (PDF/text) so chat queries are fast.
                $mt = $f->get_mimetype();
                if ($mt === 'application/pdf' || strpos($mt, 'text/') === 0) {
                    \block_openaiagent\local\filetext_store::ensure_queued($f);
                }
                $items[] = [
                    'type' => 'file',
                    'title' => $f->get_filename(),
                    'content_id' => 'file:' . (int)$f->get_id(),
                    'mimetype' => $f->get_mimetype(),
                    'size' => (int)$f->get_filesize(),
                ];
            }
        }

        return [
            'cmid' => (int)$cmid,
            'modname' => (string)$modname,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Tool: fetch a content item (text extraction).
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Content item with extracted text.
     */
    private static function get_content_item(array $input, int $boundcourseid, int $calleruserid): array {
        global $DB;

        $contentid = (string)($input['content_id'] ?? '');
        if ($contentid === '' || strpos($contentid, ':') === false) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'content_id');
        }
        $maxchars = isset($input['max_chars']) ? (int)$input['max_chars'] : 12000;
        if ($maxchars <= 0 || $maxchars > 50000) {
            $maxchars = 12000;
        }

        [$type, $id] = explode(':', $contentid, 2);
        $text = '';
        $title = '';
        $mimetype = '';
        $meta = [];

        if ($type === 'page') {
            $cmid = (int)$id;
            $cm = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
            self::require_module_in_course($cmid, $boundcourseid, (int)$cm->course);
            self::require_course_view((int)$cm->course, $calleruserid);
            self::require_module_visible($cmid, (int)$cm->course, $calleruserid);

            $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
            $context = context_module::instance($cmid);
            $html = format_text($page->content, $page->contentformat, ['context' => $context]);
            $text = trim(html_to_text($html));
            $title = $page->name ?? 'Page';
            $mimetype = 'text/plain';
        } else if ($type === 'bookchapter') {
            $chapterid = (int)$id;
            $chapter = $DB->get_record('book_chapters', ['id' => $chapterid], '*', MUST_EXIST);
            $book = $DB->get_record('book', ['id' => $chapter->bookid], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('book', $book->id, $book->course, false, MUST_EXIST);
            self::require_module_in_course((int)$cm->id, $boundcourseid, (int)$book->course);
            self::require_course_view((int)$book->course, $calleruserid);
            self::require_module_visible((int)$cm->id, (int)$book->course, $calleruserid);

            $context = context_module::instance($cm->id);
            $html = format_text($chapter->content, $chapter->contentformat, ['context' => $context]);
            $text = trim(html_to_text($html));
            $title = $chapter->title ?? 'Book chapter';
            $mimetype = 'text/plain';
        } else if ($type === 'file') {
            $fileid = (int)$id;
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($fileid);
            if (!$file) {
                throw new \moodle_exception('filenotfound', 'error');
            }
            $context = \context::instance_by_id($file->get_contextid(), MUST_EXIST);
            $courseid = null;
            $filecmid = 0;
            if ($context instanceof context_module) {
                $filecmid = (int)$context->instanceid;
                $cm = get_coursemodule_from_id(null, $filecmid, 0, false, MUST_EXIST);
                $courseid = (int)$cm->course;
            } else if ($context instanceof context_course) {
                $courseid = (int)$context->instanceid;
            }
            // Fail closed. The file id arrives from the model, which can name any
            // integer, so a file whose course cannot be established (user, block
            // or system context) used to skip BOTH the course binding and the
            // enrolment check and have its text extracted regardless.
            if (!$courseid) {
                throw new \moodle_exception('mcp_activity_not_visible', 'block_openaiagent');
            }
            self::require_module_in_course($filecmid, $boundcourseid, (int)$courseid);
            self::require_course_view($courseid, $calleruserid);
            if ($filecmid) {
                self::require_module_visible($filecmid, $courseid, $calleruserid);
            }

            $title = $file->get_filename();
            $mimetype = $file->get_mimetype();

            // Persistent indexing (queue once; serve fast afterwards).
            $contenthash = $file->get_contenthash();

            if ($mimetype === 'application/pdf' || strpos($mimetype, 'text/') === 0) {
                \block_openaiagent\local\filetext_store::ensure_queued($file);
                $res = \block_openaiagent\local\filetext_store::get_by_contenthash($contenthash);

                $status = (int)$res['status'];
                if ($status === \block_openaiagent\local\filetext_store::STATUS_READY) {
                    $text = $res['text'];
                } else if ($status === \block_openaiagent\local\filetext_store::STATUS_EMPTY) {
                    $text = '';
                } else if ($status === \block_openaiagent\local\filetext_store::STATUS_FAILED) {
                    $text = '';
                } else {
                    $text = '';
                }

                // Attach status for the agent to decide next steps.
                $meta = [
                    'index_status' => $status,
                    'index_time' => (int)$res['timeindexed'],
                    'index_error' => (string)$res['errormsg'],
                ];
            } else {
                $text = '';
                $meta = [
                    'index_status' => -1,
                    'index_time' => 0,
                    'index_error' => '',
                ];
            }

            if ($text === '') {
                // Provide a clear, actionable message instead of a generic "can't access".
                if (
                    !empty($meta) && isset($meta['index_status']) &&
                    ($meta['index_status'] === \block_openaiagent\local\filetext_store::STATUS_PENDING ||
                     $meta['index_status'] === \block_openaiagent\local\filetext_store::STATUS_PROCESSING)
                ) {
                    $text = 'This document is being indexed. Please try again in a minute.';
                } else if (
                    !empty($meta) && isset($meta['index_status']) &&
                    $meta['index_status'] === \block_openaiagent\local\filetext_store::STATUS_EMPTY
                ) {
                    $text = 'No extractable text was found in this PDF (it may be scanned or use a complex encoding).';
                } else if (
                    !empty($meta) && isset($meta['index_status']) &&
                    $meta['index_status'] === \block_openaiagent\local\filetext_store::STATUS_FAILED
                ) {
                    $text = 'Text extraction failed for this file due to a technical issue.';
                } else {
                    $text = 'Content extraction for this file type is not available. Filename: ' .
                        $title . ' (mimetype: ' . $mimetype . ').';
                }
            }
        } else if ($type === 'h5p') {
            $cmid = (int)$id;
            $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
            self::require_module_in_course($cmid, $boundcourseid, (int)$cm->course);
            self::require_course_view((int)$cm->course, $calleruserid);

            // Best-effort: return intro + note. Full H5P parsing is complex and depends on libraries.
            $h5p = $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST);
            $context = context_module::instance($cmid);
            $introhtml = format_text($h5p->intro ?? '', $h5p->introformat ?? FORMAT_HTML, ['context' => $context]);
            $introplain = trim(html_to_text($introhtml));
            $title = $h5p->name ?? 'H5P activity';
            $mimetype = 'text/plain';

            $text = $introplain;
            if ($text === '') {
                $text = 'H5P activity detected. Text extraction is limited to the activity intro/description. ' .
                    'If you need full H5P content extraction, we can add deeper parsing in a next iteration.';
            }
        } else {
            throw new \moodle_exception('invalidparameter', 'core', '', 'content_id');
        }

        if (mb_strlen($text) > $maxchars) {
            $text = mb_substr($text, 0, $maxchars) . "\n\n[Truncated to max_chars=" . $maxchars . "]";
        }

        return [
            'content_id' => $contentid,
            'title' => $title,
            'mimetype' => $mimetype,
            'content_text' => $text,
            'meta' => $meta,
        ];
    }

    /**
     * Best-effort PDF text extraction.
     *
     * Pure-PHP best-effort extractor. Falls back to empty string for scanned PDFs.
     *
     * @param string $filepath Absolute path to the PDF file.
     * @return string Extracted plain text, or empty string on failure.
     */
    private static function extract_pdf_text(string $filepath): string {
        // Pure-PHP best-effort PDF text extraction (no shell_exec / no system binaries).
        // Supports common "text-based" PDFs. Scanned/image PDFs will yield little or no text.
        if (!is_readable($filepath)) {
            return '';
        }

        // Safety limits to avoid excessive CPU/memory on huge files.
        $maxbytes = 35 * 1024 * 1024; // 35MB
        $filesize = @filesize($filepath);
        if ($filesize !== false && $filesize > $maxbytes) {
            return '';
        }

        $data = @file_get_contents($filepath);
        if ($data === false || $data === '') {
            return '';
        }

        $textparts = [];
        // Capture stream dictionaries + streams.
        if (preg_match_all('/<<(?P<dict>.*?)>>\s*stream\s*(?P<stream>.*?)\s*endstream/s', $data, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $dict = $m['dict'] ?? '';
                $stream = $m['stream'] ?? '';

                // Decode stream if needed.
                $decoded = $stream;
                $filters = self::pdf_get_filters($dict);
                foreach ($filters as $filter) {
                    $decoded = self::pdf_apply_filter($decoded, $filter, $dict);
                    if ($decoded === '') {
                        break;
                    }
                }
                if ($decoded === '') {
                    continue;
                }

                $t = self::pdf_extract_text_from_stream($decoded);
                if ($t !== '') {
                    $textparts[] = $t;
                    // Hard stop if we already have plenty of text.
                    if (mb_strlen(implode("\n", $textparts)) > 220000) {
                        break;
                    }
                }
            }
        }

        $out = trim(preg_replace("/[ \t]+/", " ", implode("\n", $textparts)));
        return $out;
    }

    /**
     * Extract filter names from a PDF stream dictionary.
     *
     * @param string $dict Stream dictionary string.
     * @return string[] List of lowercase filter names.
     */
    private static function pdf_get_filters(string $dict): array {
        $filters = [];
        if (preg_match('/\/Filter\s*(\[(.*?)\]|\/[A-Za-z0-9]+)\b/s', $dict, $m)) {
            $raw = $m[1] ?? '';
            if ($raw === '') {
                return [];
            }
            if ($raw[0] === '[') {
                if (preg_match_all('/\/([A-Za-z0-9]+)/', $raw, $mm)) {
                    foreach ($mm[1] as $f) {
                        $filters[] = $f;
                    }
                }
            } else {
                if (preg_match('/\/([A-Za-z0-9]+)/', $raw, $mm)) {
                    $filters[] = $mm[1];
                }
            }
        }
        return $filters;
    }

    /**
     * Apply a single PDF filter to a data stream.
     *
     * @param string $data   Raw stream bytes.
     * @param string $filter Lowercase filter name.
     * @param string $dict   Stream dictionary string.
     * @return string Decoded bytes, or empty string on failure.
     */
    private static function pdf_apply_filter(string $data, string $filter, string $dict = ''): string {
        $filter = strtolower($filter);
        if ($filter === 'flatedecode' || $filter === 'flate') {
            // Try zlib then raw deflate.
            $out = @gzuncompress($data);
            if ($out === false) {
                $out = @gzinflate($data);
            }
            if ($out === false || $out === null) {
                return '';
            }
            // Handle common PNG prediction used with FlateDecode (e.g., Predictor 12).
            $out = self::pdf_apply_predictor($out, $dict);
            return $out;
        }
        if ($filter === 'asciihexdecode') {
            $hex = preg_replace('/\s+/', '', $data);
            $hex = rtrim($hex, '>');
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            $bin = @hex2bin($hex);
            return $bin === false ? '' : $bin;
        }
        if ($filter === 'ascii85decode') {
            return self::pdf_decode_ascii85($data);
        }
        // Unsupported filter.
        return '';
    }

    /**
     * Decode an ASCII-85 encoded data stream.
     *
     * @param string $data Encoded data.
     * @return string Decoded bytes.
     */
    private static function pdf_decode_ascii85(string $data): string {
        $data = trim($data);
        $data = preg_replace('/^<~/', '', $data);
        $data = preg_replace('/~>$/', '', $data);
        $data = preg_replace('/\s+/', '', $data);

        $out = '';
        $group = [];
        $count = 0;

        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];
            if ($ch === 'z' && $count === 0) {
                $out .= "\x00\x00\x00\x00";
                continue;
            }
            $ord = ord($ch);
            if ($ord < 33 || $ord > 117) {
                continue;
            }
            $group[] = $ord - 33;
            $count++;
            if ($count === 5) {
                $acc = 0;
                foreach ($group as $v) {
                    $acc = $acc * 85 + $v;
                }
                $out .= pack('N', $acc);
                $group = [];
                $count = 0;
            }
        }

        if ($count > 1) {
            while ($count < 5) {
                $group[] = 84; // Pad with 'u'.
                $count++;
            }
            $acc = 0;
            foreach ($group as $v) {
                $acc = $acc * 85 + $v;
            }
            $tmp = pack('N', $acc);
            $out .= substr($tmp, 0, $count - 1);
        }

        return $out;
    }

    /**
     * Extract text content from a decompressed PDF content stream.
     *
     * @param string $stream Decompressed PDF content stream.
     * @return string Extracted text, space-separated.
     */
    private static function pdf_extract_text_from_stream(string $stream): string {
        $out = [];

        if (!preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) {
            return '';
        }

        foreach ($blocks[1] as $b) {
            // Literal string text operator.
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $b, $m1)) {
                foreach ($m1[0] as $chunk) {
                    if (preg_match('/^\((.*)\)\s*Tj/s', $chunk, $mm)) {
                        $out[] = self::pdf_unescape_string($mm[1]);
                    }
                }
            }
            // Array text operator.
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $b, $m2)) {
                foreach ($m2[1] as $arr) {
                    if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arr, $sm)) {
                        foreach ($sm[0] as $s) {
                            $out[] = self::pdf_unescape_string(substr($s, 1, -1));
                        }
                    }
                }
            }
            // Hex string text operator.
            if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/s', $b, $m3)) {
                foreach ($m3[1] as $hex) {
                    $hex = preg_replace('/\s+/', '', $hex);
                    $bin = @hex2bin($hex);
                    if ($bin !== false && $bin !== '') {
                        $out[] = trim($bin);
                    }
                }
            }
            $out[] = "\n";
        }

        $txt = trim(implode(' ', $out));
        $txt = preg_replace("/\s+\n\s+/", "\n", $txt);
        $txt = preg_replace("/[ \t]+/", " ", $txt);
        return trim($txt);
    }

    /**
     * Unescape a PDF literal string.
     *
     * @param string $s Raw PDF literal string content.
     * @return string Unescaped string.
     */
    private static function pdf_unescape_string(string $s): string {
        // Handle common PDF escape sequences and octal escapes.
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $s);

        $map = [
            '\\\\' => "\\",
            '\(' => '(',
            '\)' => ')',
            '\n' => "\n",
            '\r' => "\r",
            '\t' => "\t",
            '\b' => "\b",
            '\f' => "\f",
        ];
        $s = strtr($s, $map);

        // Remove escaped line continuation.
        $s = preg_replace("/\\\\\r?\n/", '', $s);
        return trim($s);
    }

    /**
     * Tool: return user's groups for a course.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array User groups array.
     */
    private static function get_user_groups(array $input, int $courseid, int $calleruserid): array {
        global $DB;

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once(__DIR__ . '/../../../../group/lib.php');

        $groupsbygrouping = groups_get_user_groups($courseid, $targetuserid);
        $groupids = [];
        foreach ($groupsbygrouping as $groupingid => $gids) {
            foreach ($gids as $gid) {
                $groupids[(int)$gid] = true;
            }
        }
        $groupids = array_keys($groupids);

        $groups = [];
        if (!empty($groupids)) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('groups', "id $insql", $params, 'name ASC', 'id, name, idnumber');
            foreach ($records as $g) {
                $groups[] = [
                    'id' => (int)$g->id,
                    'name' => format_string($g->name),
                    'idnumber' => $g->idnumber ?? '',
                ];
            }
        }

        return [
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'groups' => $groups,
            'group_count' => count($groups),
        ];
    }

    /**
     * Tool: return calendar events for a course within a time window.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Calendar events array.
     */
    private static function get_calendar_events(array $input, int $courseid, int $calleruserid): array {
        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once(__DIR__ . '/../../../../calendar/lib.php');

        $from = isset($input['time_from']) ? (int)$input['time_from'] : time();
        $to = isset($input['time_to']) ? (int)$input['time_to'] : ($from + 30 * DAYSECS);
        $limit = isset($input['limit']) ? max(1, min(200, (int)$input['limit'])) : 20;

        $events = calendar_get_events($from, $to, [$targetuserid], null, [$courseid], true);

        usort($events, function ($a, $b) {
            return ((int)$a->timestart) <=> ((int)$b->timestart);
        });

        $out = [];
        foreach ($events as $e) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = [
                'id' => (int)$e->id,
                'name' => format_string($e->name),
                'timestart' => (int)$e->timestart,
                'timeduration' => (int)($e->timeduration ?? 0),
                'location' => $e->location ?? '',
                'courseid' => (int)($e->courseid ?? 0),
                'eventtype' => $e->eventtype ?? '',
                'visible' => isset($e->visible) ? (bool)$e->visible : true,
                'description' => isset($e->description) ? trim(html_to_text($e->description)) : '',
            ];
        }

        return [
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'time_from' => $from,
            'time_to' => $to,
            'events' => $out,
            'event_count' => count($out),
        ];
    }

    /**
     * Tool: return gradebook items and current user grades.
     *
     * Note: exact weighting depends on category aggregation settings. We return explicit weights
     * when configured (aggregationcoef2), and grademax to help infer 'natural' aggregation.
     *
     * @param array $input Tool input arguments.
     * @param int $courseid Course id.
     * @param int $calleruserid Bound user id.
     * @return array Gradebook items array.
     */
    private static function get_gradebook_items(array $input, int $courseid, int $calleruserid): array {
        global $CFG;

        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        $includehidden = !empty($input['include_hidden']);
        $limit = isset($input['limit']) ? max(1, min(1000, (int)$input['limit'])) : 200;

        $items = \grade_item::fetch_all(['courseid' => $courseid]) ?? [];
        $grades = grade_get_grades($courseid, 'course', null, null, $targetuserid);

        $grademap = [];
        if (!empty($grades->items)) {
            foreach ($grades->items as $gi) {
                $grademap[(int)$gi->id] = $gi;
            }
        }

        $outitems = [];
        foreach ($items as $item) {
            if (count($outitems) >= $limit) {
                break;
            }
            if (!$includehidden && !empty($item->hidden)) {
                continue;
            }

            $gi = $grademap[(int)$item->id] ?? null;
            $usergrade = null;
            if ($gi && !empty($gi->grades) && isset($gi->grades[$targetuserid])) {
                $g = $gi->grades[$targetuserid];
                $usergrade = [
                    'grade' => isset($g->grade) ? (float)$g->grade : null,
                    'grade_formatted' => $g->str_grade ?? null,
                    'hidden' => !empty($g->hidden),
                    'overridden' => !empty($g->overridden),
                    'excluded' => !empty($g->excluded),
                    'feedback' => isset($g->str_feedback) ? trim(html_to_text($g->str_feedback)) : '',
                ];
            }

            $outitems[] = [
                'id' => (int)$item->id,
                'itemname' => format_string($item->get_name()),
                'itemtype' => $item->itemtype,
                'itemmodule' => $item->itemmodule ?? '',
                'iteminstance' => (int)($item->iteminstance ?? 0),
                'cmid' => (int)($item->cmid ?? 0),
                'categoryid' => (int)($item->categoryid ?? 0),
                'grademin' => (float)$item->grademin,
                'grademax' => (float)$item->grademax,
                'gradepass' => (float)($item->gradepass ?? 0),
                'hidden' => !empty($item->hidden),
                'weight' => isset($item->aggregationcoef2) ? (float)$item->aggregationcoef2 : null,
                'user_grade' => $usergrade,
            ];
        }

        $coursetotal = null;
        foreach ($outitems as $oi) {
            if ($oi['itemtype'] === 'course') {
                $coursetotal = $oi;
                break;
            }
        }

        return [
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'course_total' => $coursetotal,
            'items' => $outitems,
            'item_count' => count($outitems),
            'notes' => [
                'weights' => 'The "weight" field is only populated when explicit weights are configured. ' .
                    'For natural aggregation, use grademax and category settings to infer relative contribution.',
            ],
        ];
    }

    /**
     * Tool: assignment submission status and key settings.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Assignment status array.
     */
    private static function get_assign_submission_status(array $input, int $boundcourseid, int $calleruserid): array {
        global $CFG, $DB;

        if (empty($input['cmid'])) {
            throw new \moodle_exception('missingparameter', 'core', '', 'cmid');
        }
        $cmid = (int)$input['cmid'];

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);
        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = context_module::instance($cmid);
        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();

        $submission = $assign->get_user_submission($targetuserid, false);

        // Read through the methods mod_assign actually exposes. This used to
        // call $assign->get_submission_status(), which does not exist -- the
        // renderer-facing method is get_assign_submission_status_renderable() --
        // so every single call threw "undefined method", was swallowed by the
        // tool executor and came back to the model as a bare tool_failed. The
        // tool had never worked.
        $flags = $assign->get_user_flags($targetuserid, false);
        $gradingstatus = $assign->get_grading_status($targetuserid);
        $canedit = $assign->submissions_open($targetuserid, false, $submission ?: false, $flags ?: false);

        $submitted = false;
        $timemodified = null;
        $attemptnumber = null;

        if ($submission) {
            $submitted = ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED);
            $timemodified = (int)$submission->timemodified;
            $attemptnumber = isset($submission->attemptnumber) ? (int)$submission->attemptnumber : null;
        }

        $duedate = (int)($instance->duedate ?? 0);
        $late = false;
        if ($submitted && $duedate > 0 && $timemodified && $timemodified > $duedate) {
            $late = true;
        }

        return [
            'cmid' => $cmid,
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'assignment' => [
                'name' => format_string($instance->name),
                'allowsubmissionsfromdate' => (int)($instance->allowsubmissionsfromdate ?? 0),
                'duedate' => $duedate,
                'cutoffdate' => (int)($instance->cutoffdate ?? 0),
                'grade' => (float)($instance->grade ?? 0),
                'maxattempts' => (int)($instance->maxattempts ?? -1),
                'attemptreopenmethod' => $instance->attemptreopenmethod ?? '',
            ],
            'submission' => [
                'exists' => (bool)$submission,
                'status' => $submission ? (string)$submission->status : 'none',
                'submitted' => $submitted,
                'timemodified' => $timemodified,
                'attemptnumber' => $attemptnumber,
                'late' => $late,
            ],
            'status_summary' => [
                // Whether the participant could submit or change their work now.
                'canedit' => (bool)$canedit,
                // Set by a teacher to stop further submissions from this person.
                'locked' => !empty($flags->locked),
                'gradingstatus' => (string)$gradingstatus,
                'graded' => $gradingstatus === ASSIGN_GRADING_STATUS_GRADED,
                'workflowstate' => isset($flags->workflowstate) ? (string)$flags->workflowstate : '',
            ],
        ];
    }

    /**
     * Tool: quiz settings + attempts list.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Quiz and attempts array.
     */
    private static function get_quiz_attempts(array $input, int $boundcourseid, int $calleruserid): array {
        global $CFG, $DB;

        if (empty($input['cmid'])) {
            throw new \moodle_exception('missingparameter', 'core', '', 'cmid');
        }
        $cmid = (int)$input['cmid'];
        $limit = isset($input['limit']) ? max(1, min(200, (int)$input['limit'])) : 20;

        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);
        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $attempts = quiz_get_user_attempts($quiz->id, $targetuserid, 'all', true);

        usort($attempts, function ($a, $b) {
            return ((int)$a->attempt) <=> ((int)$b->attempt);
        });

        $out = [];
        foreach ($attempts as $a) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = [
                'id' => (int)$a->id,
                'attempt' => (int)$a->attempt,
                'state' => (string)$a->state,
                'timestart' => (int)$a->timestart,
                'timefinish' => (int)$a->timefinish,
                'sumgrades' => isset($a->sumgrades) ? (float)$a->sumgrades : null,
            ];
        }

        return [
            'cmid' => $cmid,
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'quiz' => [
                'name' => format_string($quiz->name),
                'attempts_allowed' => (int)$quiz->attempts,
                'timelimit' => (int)$quiz->timelimit,
                'timeopen' => (int)$quiz->timeopen,
                'timeclose' => (int)$quiz->timeclose,
                'grademethod' => (int)$quiz->grademethod,
                'preferredbehaviour' => (string)$quiz->preferredbehaviour,
            ],
            'attempts' => $out,
            'attempt_count' => count($out),
        ];
    }

    /**
     * Tool: forum participation summary + subscription status.
     *
     * @param array $input Tool input arguments.
     * @param int $boundcourseid Session-bound course id.
     * @param int $calleruserid Bound user id.
     * @return array Forum participation array.
     */
    private static function get_forum_participation(array $input, int $boundcourseid, int $calleruserid): array {
        global $CFG, $DB;

        if (empty($input['cmid'])) {
            throw new \moodle_exception('missingparameter', 'core', '', 'cmid');
        }
        $cmid = (int)$input['cmid'];

        $cm = get_coursemodule_from_id('forum', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        self::require_module_in_course($cmid, $boundcourseid, $courseid);
        $targetuserid = self::resolve_userid($input, $calleruserid);
        self::require_course_view($courseid, $targetuserid);

        require_once($CFG->dirroot . '/mod/forum/lib.php');
        require_once($CFG->dirroot . '/mod/forum/classes/subscriptions.php');

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cmid);

        $subscriptionmode = \mod_forum\subscriptions::get_subscription_mode($forum, $context);
        $issubscribed = \mod_forum\subscriptions::is_subscribed($targetuserid, $forum, $context);

        $postcount = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forumid AND p.userid = :userid",
            ['forumid' => $forum->id, 'userid' => $targetuserid]
        );

        $discussioncount = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {forum_discussions} d
              WHERE d.forum = :forumid AND d.userid = :userid",
            ['forumid' => $forum->id, 'userid' => $targetuserid]
        );

        $lastpost = $DB->get_field_sql(
            "SELECT MAX(p.created)
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forumid AND p.userid = :userid",
            ['forumid' => $forum->id, 'userid' => $targetuserid]
        );

        return [
            'cmid' => $cmid,
            'course_id' => $courseid,
            'user_id' => $targetuserid,
            'forum' => [
                'name' => format_string($forum->name),
                'type' => (string)$forum->type,
                'subscription_mode' => (int)$subscriptionmode,
                'force_subscribe' => (int)($forum->forcesubscribe ?? 0),
            ],
            'subscription' => [
                'is_subscribed' => (bool)$issubscribed,
            ],
            'participation' => [
                'post_count' => (int)$postcount,
                'discussion_count' => (int)$discussioncount,
                'last_post_time' => $lastpost ? (int)$lastpost : null,
            ],
        ];
    }

    /**
     * Tool: prepare a support request for the participant to confirm.
     *
     * Creates a draft and nothing else. No message is composed, no address is
     * read and no mail is possible from here: confirmation is a separate,
     * explicitly authorised step. The model contributes the summary text and a
     * category from a closed list, and neither can influence where the message
     * eventually goes.
     *
     * @param array $input Tool input arguments.
     * @param int $userid Bound user id.
     * @param int $courseid Bound course id.
     * @param array $context Orchestration context (conversationid, blockinstanceid).
     * @return array Tool output.
     */
    private static function support_request_draft(array $input, int $userid, int $courseid, array $context): array {
        self::require_course_view($courseid, $userid);

        $conversationid = (int)($context['conversationid'] ?? 0);
        $blockinstanceid = (int)($context['blockinstanceid'] ?? 0);

        $config = \block_openaiagent\local\course_config::resolve($courseid, $blockinstanceid);

        // The gate already decided this before the tool was exposed. Repeating
        // the hard half here means a future refactor that reshuffles exposure
        // cannot silently turn the block into something that files requests
        // without permission or past its ceilings.
        $blocked = \block_openaiagent\local\support_gate::hard_preconditions(
            $config,
            $conversationid,
            $userid,
            $courseid
        );
        if ($blocked !== '') {
            return [
                'ok' => false,
                'reason' => $blocked,
                'message' => get_string('mcp_support_draft_refused', 'block_openaiagent'),
            ];
        }

        $summary = \block_openaiagent\local\supportrequest::clean_summary((string)($input['summary'] ?? ''));
        if (trim($summary) === '') {
            return [
                'ok' => false,
                'reason' => 'emptysummary',
                'message' => get_string('mcp_support_draft_empty', 'block_openaiagent'),
            ];
        }

        // The same complaint, reworded, must not become a second ticket. This is
        // the cheap half of the anti-spam work: it costs one indexed lookup and
        // it removes the most common cause of duplicates, which is a participant
        // who did not get an answer fast enough and asked again.
        $duplicate = \block_openaiagent\local\supportrequest::find_duplicate(
            $courseid,
            $userid,
            \block_openaiagent\local\supportrequest::summary_hash($summary),
            (int)($config['support']['dedupehours'] ?? 0)
        );
        if ($duplicate !== null) {
            return [
                'ok' => false,
                'reason' => 'duplicate',
                'ticket_reference' => (string)$duplicate->ticketref,
                'sent_on' => userdate((int)$duplicate->timecreated),
                'message' => get_string('mcp_support_draft_duplicate', 'block_openaiagent'),
            ];
        }

        $draft = \block_openaiagent\local\supportrequest::create_draft(
            $courseid,
            $blockinstanceid,
            $userid,
            $conversationid,
            $summary,
            (string)($input['category'] ?? '')
        );

        return [
            'ok' => true,
            'ticket_reference' => (string)$draft->ticketref,
            'category' => (string)$draft->category,
            'message' => get_string('mcp_support_draft_ready', 'block_openaiagent'),
        ];
    }

    /**
     * Tool: the participant's own support requests in this course.
     *
     * Read-only and scoped to the caller. It returns a reference, a category, a
     * status and dates: never a destination address, never another
     * participant's row.
     *
     * @param int $userid Bound user id.
     * @param int $courseid Bound course id.
     * @return array Tool output.
     */
    private static function support_request_status(int $userid, int $courseid): array {
        self::require_course_view($courseid, $userid);

        $requests = \block_openaiagent\local\supportrequest::recent_for_user($courseid, $userid);

        return [
            'count' => count($requests),
            'requests' => $requests,
        ];
    }

    /**
     * Tool: return support link.
     *
     * @param int $userid Bound user id.
     * @param int $courseid Course id.
     * @return array Support link array.
     */
    private static function get_support_link(int $userid, int $courseid): array {
        // Use the shared check, like every other tool. This one tested
        // moodle/course:view on its own, and that capability means "view courses
        // WITHOUT participating" -- managers have it, enrolled students do not.
        // So this tool threw for every student it was meant to serve: the support
        // link was unreachable for exactly the people who need it, and the
        // assistant had to fall back to whatever URL its prompt happened to carry.
        self::require_course_view($courseid, $userid);

        $url = (string)get_config('block_openaiagent', 'support_url');
        $title = get_string('mcp_support_title', 'block_openaiagent');

        if ($url === '') {
            return [
                'available' => false,
                'title' => $title,
                'url' => '',
                'message' => get_string('mcp_support_not_configured', 'block_openaiagent'),
            ];
        }

        // Validate URL format.
        $url = clean_param($url, PARAM_URL);

        return [
            'available' => true,
            'title' => $title,
            'url' => $url,
        ];
    }
}
