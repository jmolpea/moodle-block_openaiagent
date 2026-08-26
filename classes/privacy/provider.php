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
 * Privacy provider for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation.
 *
 * Stored conversations and their messages may additionally be subject to
 * optional automatic retention: when the admin setting
 * `conversation_retention_days` is greater than zero, the
 * `\block_openaiagent\task\purge_conversations_task` scheduled task deletes
 * conversations (and their messages) that have not been updated within the
 * configured window. The default of 0 keeps conversations indefinitely.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Get the list of metadata about data stored.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_openaiagent_usage',
            [
                'userid' => 'privacy:metadata:openaiagent_usage:userid',
                'blockid' => 'privacy:metadata:openaiagent_usage:blockid',
                'conversationcount' => 'privacy:metadata:openaiagent_usage:conversationcount',
                'timecreated' => 'privacy:metadata:openaiagent_usage:timecreated',
            ],
            'privacy:metadata:openaiagent_usage'
        );

        $collection->add_database_table(
            'block_openaiagent_conversations',
            [
                'userid' => 'privacy:metadata:openaiagent_conversations:userid',
                'courseid' => 'privacy:metadata:openaiagent_conversations:courseid',
                'conversationsummary' => 'privacy:metadata:openaiagent_conversations:conversationsummary',
            ],
            'privacy:metadata:openaiagent_conversations'
        );

        $collection->add_database_table(
            'block_openaiagent_messages',
            [
                'role' => 'privacy:metadata:openaiagent_messages:role',
                'content' => 'privacy:metadata:openaiagent_messages:content',
            ],
            'privacy:metadata:openaiagent_messages'
        );

        // Per-user-per-day question counts, kept for the analytics dashboard.
        // It holds no message text, but "user X asked N questions in course Y on
        // day D" is personal data all the same, and it survived erasure requests
        // while it went undeclared here.
        $collection->add_database_table(
            'block_openaiagent_userstats',
            [
                'userid' => 'privacy:metadata:openaiagent_userstats:userid',
                'courseid' => 'privacy:metadata:openaiagent_userstats:courseid',
                'daterecorded' => 'privacy:metadata:openaiagent_userstats:daterecorded',
                'numquestions' => 'privacy:metadata:openaiagent_userstats:numquestions',
            ],
            'privacy:metadata:openaiagent_userstats'
        );

        // The escalation requests. This is the one table in the plugin that holds
        // an address a support team can write back to, so it is declared in
        // full: what was asked, when, where it went, and how it ended.
        $collection->add_database_table(
            'block_openaiagent_supportreq',
            [
                'userid' => 'privacy:metadata:openaiagent_supportreq:userid',
                'courseid' => 'privacy:metadata:openaiagent_supportreq:courseid',
                'category' => 'privacy:metadata:openaiagent_supportreq:category',
                'summary' => 'privacy:metadata:openaiagent_supportreq:summary',
                'status' => 'privacy:metadata:openaiagent_supportreq:status',
                'ticketref' => 'privacy:metadata:openaiagent_supportreq:ticketref',
                'recipients' => 'privacy:metadata:openaiagent_supportreq:recipients',
                'timecreated' => 'privacy:metadata:openaiagent_supportreq:timecreated',
                'timesent' => 'privacy:metadata:openaiagent_supportreq:timesent',
            ],
            'privacy:metadata:openaiagent_supportreq'
        );

        $collection->add_external_location_link(
            'supportmailbox',
            [
                'fullname' => 'privacy:metadata:supportmailbox:fullname',
                'email' => 'privacy:metadata:supportmailbox:email',
                'summary' => 'privacy:metadata:supportmailbox:summary',
            ],
            'privacy:metadata:supportmailbox'
        );

        // Everything that actually leaves the site for the model, named field
        // by field. The user id is deliberately NOT among them: the identity
        // block sends a first name and nothing else. What is easy to overlook,
        // and what a data protection officer will ask about, is the fourth
        // entry - the output of the Moodle tools is fed back to the model so it
        // can reason about it, and that output carries the participant's grades
        // and submission states.
        $collection->add_external_location_link(
            'aiprovider',
            [
                'firstname' => 'privacy:metadata:aiprovider:firstname',
                'coursename' => 'privacy:metadata:aiprovider:coursename',
                'messages' => 'privacy:metadata:aiprovider:messages',
                'toolresults' => 'privacy:metadata:aiprovider:toolresults',
            ],
            'privacy:metadata:externalpurpose'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information.
     *
     * @param int $userid The user ID.
     * @return contextlist The contextlist.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {block_instances} bi ON bi.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {block_openaiagent_usage} u ON u.blockid = bi.id
                 WHERE u.userid = :userid
                   AND bi.blockname = 'openaiagent'";

        $params = [
            'contextlevel' => CONTEXT_BLOCK,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        // Conversation data lives at course context.
        $coursesql = "SELECT DISTINCT ctx.id
                        FROM {context} ctx
                        JOIN {block_openaiagent_conversations} c
                          ON c.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                       WHERE c.userid = :userid";

        $contextlist->add_from_sql($coursesql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        // Analytics rollups are also keyed by course, and they outlive the
        // conversations: purge_conversations_task can delete a user's
        // conversations while their per-day counts remain, so a user with no
        // conversation left still has data in this table.
        $statssql = "SELECT DISTINCT ctx.id
                       FROM {context} ctx
                       JOIN {block_openaiagent_userstats} s
                         ON s.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                      WHERE s.userid = :userid";

        $contextlist->add_from_sql($statssql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        // Escalations survive their conversation: purging conversations does not
        // touch them, so a participant with nothing left in the chat may still
        // have requests recorded here.
        $supportsql = "SELECT DISTINCT ctx.id
                         FROM {context} ctx
                         JOIN {block_openaiagent_supportreq} r
                           ON r.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                        WHERE r.userid = :userid";

        $contextlist->add_from_sql($supportsql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users in a context.
     *
     * @param userlist $userlist The userlist.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel == CONTEXT_BLOCK) {
            $sql = "SELECT u.userid
                      FROM {block_openaiagent_usage} u
                      JOIN {block_instances} bi ON bi.id = u.blockid
                     WHERE bi.id = :blockid
                       AND bi.blockname = 'openaiagent'";

            $userlist->add_from_sql('userid', $sql, ['blockid' => $context->instanceid]);
            return;
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {block_openaiagent_conversations} WHERE courseid = :courseid",
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {block_openaiagent_userstats} WHERE courseid = :courseid",
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {block_openaiagent_supportreq} WHERE courseid = :courseid",
                ['courseid' => $context->instanceid]
            );
        }
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist The approved contextlist.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_BLOCK) {
                $records = $DB->get_records('block_openaiagent_usage', [
                    'userid' => $userid,
                    'blockid' => $context->instanceid,
                ]);

                if (!empty($records)) {
                    $data = [];
                    foreach ($records as $record) {
                        $data[] = [
                            'conversationcount' => $record->conversationcount,
                            'date' => userdate($record->daterecorded),
                            'timecreated' => userdate($record->timecreated),
                        ];
                    }

                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'block_openaiagent')],
                        (object)['usage' => $data]
                    );
                }
                continue;
            }

            if ($context->contextlevel == CONTEXT_COURSE) {
                self::export_course_conversations($context, $userid);
                self::export_course_userstats($context, $userid);
                self::export_course_supportreq($context, $userid);
            }
        }
    }

    /**
     * Export a user's per-day question counts within a course context.
     *
     * @param \context $context The course context.
     * @param int $userid The user whose data is being exported.
     */
    protected static function export_course_userstats(\context $context, int $userid): void {
        global $DB;

        $records = $DB->get_records('block_openaiagent_userstats', [
            'userid' => $userid,
            'courseid' => $context->instanceid,
        ], 'daterecorded ASC');

        if (empty($records)) {
            return;
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = [
                'date' => userdate($record->daterecorded),
                'numquestions' => (int)$record->numquestions,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'block_openaiagent'), get_string('privacy:path:userstats', 'block_openaiagent')],
            (object)['userstats' => $data]
        );
    }

    /**
     * Export a user's support escalations within a course context.
     *
     * @param \context $context The course context.
     * @param int $userid The user whose data is being exported.
     */
    protected static function export_course_supportreq(\context $context, int $userid): void {
        global $DB;

        $records = $DB->get_records('block_openaiagent_supportreq', [
            'userid' => $userid,
            'courseid' => $context->instanceid,
        ], 'timecreated ASC');

        if (empty($records)) {
            return;
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = [
                'reference' => (string)$record->ticketref,
                'category' => (string)$record->category,
                'status' => (string)$record->status,
                'summary' => (string)$record->summary,
                'recipients' => (string)$record->recipients,
                'timecreated' => userdate((int)$record->timecreated),
                'timesent' => (int)$record->timesent > 0 ? userdate((int)$record->timesent) : '',
            ];
        }

        writer::with_context($context)->export_data(
            [
                get_string('pluginname', 'block_openaiagent'),
                get_string('privacy:path:supportreq', 'block_openaiagent'),
            ],
            (object)['supportrequests' => $data]
        );
    }

    /**
     * Export a user's conversations and messages within a course context.
     *
     * @param \context $context The course context.
     * @param int $userid The user whose data is being exported.
     */
    protected static function export_course_conversations(\context $context, int $userid): void {
        global $DB;

        $conversations = $DB->get_records('block_openaiagent_conversations', [
            'userid' => $userid,
            'courseid' => $context->instanceid,
        ], 'timecreated ASC');

        if (empty($conversations)) {
            return;
        }

        foreach ($conversations as $conversation) {
            $messages = $DB->get_records(
                'block_openaiagent_messages',
                ['conversationid' => $conversation->id],
                'timecreated ASC'
            );

            $exportmessages = [];
            foreach ($messages as $message) {
                $exportmessages[] = [
                    'role' => $message->role,
                    'content' => (string)$message->content,
                    'route' => $message->route,
                    'timecreated' => userdate($message->timecreated),
                ];
            }

            $data = (object)[
                'currentintent' => $conversation->currentintent,
                'conversationsummary' => (string)$conversation->conversationsummary,
                'timecreated' => userdate($conversation->timecreated),
                'messages' => $exportmessages,
            ];

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_openaiagent'), $conversation->id],
                $data
            );
        }
    }

    /**
     * Delete all user data.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_BLOCK) {
            $DB->delete_records('block_openaiagent_usage', ['blockid' => $context->instanceid]);
            return;
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            self::delete_course_messages_for_conversations(
                "courseid = :courseid",
                ['courseid' => $context->instanceid]
            );
            $DB->delete_records('block_openaiagent_conversations', ['courseid' => $context->instanceid]);
            $DB->delete_records('block_openaiagent_userstats', ['courseid' => $context->instanceid]);
            $DB->delete_records('block_openaiagent_supportreq', ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete messages belonging to conversations matched by a SQL fragment.
     *
     * @param string $conversationselect WHERE fragment selecting conversation rows.
     * @param array $params Named parameters for the fragment.
     */
    protected static function delete_course_messages_for_conversations(string $conversationselect, array $params): void {
        global $DB;

        $conversationids = $DB->get_fieldset_select(
            'block_openaiagent_conversations',
            'id',
            $conversationselect,
            $params
        );

        if (empty($conversationids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($conversationids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('block_openaiagent_messages', "conversationid {$insql}", $inparams);
    }

    /**
     * Delete user data.
     *
     * @param approved_contextlist $contextlist The approved contextlist.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_BLOCK) {
                $DB->delete_records('block_openaiagent_usage', [
                    'userid' => $userid,
                    'blockid' => $context->instanceid,
                ]);
                continue;
            }

            if ($context->contextlevel == CONTEXT_COURSE) {
                self::delete_course_messages_for_conversations(
                    "courseid = :courseid AND userid = :userid",
                    ['courseid' => $context->instanceid, 'userid' => $userid]
                );
                $DB->delete_records('block_openaiagent_conversations', [
                    'courseid' => $context->instanceid,
                    'userid' => $userid,
                ]);
                $DB->delete_records('block_openaiagent_userstats', [
                    'courseid' => $context->instanceid,
                    'userid' => $userid,
                ]);
                $DB->delete_records('block_openaiagent_supportreq', [
                    'courseid' => $context->instanceid,
                    'userid' => $userid,
                ]);
            }
        }
    }

    /**
     * Delete data for users.
     *
     * @param approved_userlist $userlist The approved userlist.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context->contextlevel == CONTEXT_BLOCK) {
            $params = array_merge(['blockid' => $context->instanceid], $userparams);
            $DB->delete_records_select(
                'block_openaiagent_usage',
                "blockid = :blockid AND userid {$usersql}",
                $params
            );
            return;
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            $params = array_merge(['courseid' => $context->instanceid], $userparams);
            self::delete_course_messages_for_conversations(
                "courseid = :courseid AND userid {$usersql}",
                $params
            );
            $DB->delete_records_select(
                'block_openaiagent_conversations',
                "courseid = :courseid AND userid {$usersql}",
                $params
            );
            $DB->delete_records_select(
                'block_openaiagent_userstats',
                "courseid = :courseid AND userid {$usersql}",
                $params
            );
            $DB->delete_records_select(
                'block_openaiagent_supportreq',
                "courseid = :courseid AND userid {$usersql}",
                $params
            );
        }
    }
}
