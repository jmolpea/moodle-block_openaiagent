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
 * Conversation and message persistence.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Loads, creates and updates conversations and their messages.
 */
class conversation_repository {
    /** @var string Conversations table. */
    private const CONVERSATIONS = 'block_openaiagent_conversations';

    /** @var string Messages table. */
    private const MESSAGES = 'block_openaiagent_messages';

    /**
     * Get a conversation by id, enforcing ownership.
     *
     * Returns null if the conversation does not exist or is not owned by the
     * given user/course pair. This is the cross-user access guard.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Expected owner.
     * @param int $courseid Expected course.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass|null
     */
    public static function get_owned(int $conversationid, int $userid, int $courseid, int $blockinstanceid = 0): ?\stdClass {
        global $DB;
        if ($conversationid <= 0) {
            return null;
        }
        $record = $DB->get_record(self::CONVERSATIONS, [
            'id' => $conversationid,
            'userid' => $userid,
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ]);
        return $record ?: null;
    }

    /**
     * Get the most recent conversation for a user in a course profile.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass|null
     */
    public static function get_latest(int $userid, int $courseid, int $blockinstanceid = 0): ?\stdClass {
        global $DB;
        $records = $DB->get_records(self::CONVERSATIONS, [
            'userid' => $userid,
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ], 'timemodified DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Load an owned conversation, or create a fresh one.
     *
     * @param int|null $conversationid Requested conversation id (may be null).
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass Conversation record.
     */
    public static function get_or_create(?int $conversationid, int $userid, int $courseid, int $blockinstanceid = 0): \stdClass {
        if (!empty($conversationid)) {
            $existing = self::get_owned($conversationid, $userid, $courseid, $blockinstanceid);
            if ($existing !== null) {
                return $existing;
            }
        }
        return self::create($userid, $courseid, $blockinstanceid);
    }

    /**
     * Create a new, empty conversation.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass
     */
    public static function create(int $userid, int $courseid, int $blockinstanceid = 0): \stdClass {
        global $DB;
        $now = time();
        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->blockinstanceid = $blockinstanceid;
        $record->userid = $userid;
        $record->currentintent = '';
        $record->activeagentid = 0;
        $record->lastresponseid = '';
        $record->conversationsummary = '';
        $record->lastuserrequest = '';
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record(self::CONVERSATIONS, $record);
        return $record;
    }

    /**
     * Persist routing/state changes on a conversation.
     *
     * @param \stdClass $conversation Conversation record (must have id).
     * @param array $changes Field => value updates.
     * @return void
     */
    public static function update(\stdClass $conversation, array $changes): void {
        global $DB;
        $allowed = ['currentintent', 'activeagentid', 'lastresponseid', 'conversationsummary', 'lastuserrequest'];
        $record = new \stdClass();
        $record->id = $conversation->id;
        foreach ($allowed as $field) {
            if (array_key_exists($field, $changes)) {
                $record->$field = $changes[$field];
                $conversation->$field = $changes[$field];
            }
        }
        $record->timemodified = time();
        $conversation->timemodified = $record->timemodified;
        $DB->update_record(self::CONVERSATIONS, $record);
    }

    /**
     * Whether the conversation's course profile allows storing message text.
     *
     * add_message() only receives a conversation id, so the owning profile is
     * resolved from it. Deliberately uncached: course_config::is_enabled()
     * already reads the same row unguarded on every turn, so a second indexed
     * lookup is nothing next to the model round trip it accompanies, and a
     * static cache here would survive resetAfterTest() and answer for a reused
     * conversation id in the next test.
     *
     * An unresolvable conversation keeps the historical behaviour (store),
     * matching the default of a profile that was never configured.
     *
     * @param int $conversationid Conversation id.
     * @return bool
     */
    private static function stores_conversations(int $conversationid): bool {
        global $DB;

        $conversation = $DB->get_record(
            self::CONVERSATIONS,
            ['id' => $conversationid],
            'courseid, blockinstanceid',
            IGNORE_MISSING
        );

        if (!$conversation) {
            return true;
        }

        return course_config::stores_conversations(
            (int)$conversation->courseid,
            (int)$conversation->blockinstanceid
        );
    }

    /**
     * Append a message to a conversation.
     *
     * Honours the log_messages setting: when disabled, message content is not
     * stored (only metadata such as tokens and route are kept).
     *
     * @param int $conversationid Conversation id.
     * @param string $role user|assistant|system.
     * @param string $content Message content.
     * @param array $meta Optional metadata (route, agentid, model, openairesponseid,
     *                    prompttokens, cachedtokens, completiontokens, totaltokens,
     *                    errormessage).
     * @return int New message id.
     */
    public static function add_message(int $conversationid, string $role, string $content, array $meta = []): int {
        global $DB;

        // Two independent switches, and content is kept only if BOTH allow it:
        // the site-wide log_messages setting, and the per-course
        // storeconversations setting. Either one turned off means the row is
        // written with empty content, so tokens, route and cost still reach the
        // dashboard while nothing the participant wrote is retained.
        $logmessages = (int)get_config('block_openaiagent', 'log_messages') === 1;
        $storecontent = $logmessages && self::stores_conversations($conversationid);

        $record = new \stdClass();
        $record->conversationid = $conversationid;
        $record->role = $role;
        $record->content = $storecontent ? $content : '';
        $record->route = (string)($meta['route'] ?? '');
        $record->agentid = (int)($meta['agentid'] ?? 0);
        // The model actually called, resolved per turn. It must be stored on the
        // message: the agent's defaultmodel is only the last of three precedence
        // levels (course override > admin setting > agent default), so it is not
        // a usable proxy for what was really billed.
        $record->model = \core_text::substr((string)($meta['model'] ?? ''), 0, 100);
        $record->openairesponseid = (string)($meta['openairesponseid'] ?? '');
        $record->prompttokens = (int)($meta['prompttokens'] ?? 0);
        $record->cachedtokens = (int)($meta['cachedtokens'] ?? 0);
        $record->completiontokens = (int)($meta['completiontokens'] ?? 0);
        $record->totaltokens = (int)($meta['totaltokens'] ?? 0);
        $record->errormessage = (string)($meta['errormessage'] ?? '');
        $record->timecreated = time();

        return $DB->insert_record(self::MESSAGES, $record);
    }

    /**
     * Record the token usage of an internal model call that produces no visible
     * message (the intent router and the retrieval query rewriter).
     *
     * These calls are billed exactly like the visible ones but were invisible to
     * the cost dashboard, which made every reported figure an undercount of the
     * real spend. They are stored with role 'system' so they add tokens without
     * touching any question/answer counter, and the chat UI already skips that
     * role. Calls that report no tokens write nothing.
     *
     * @param int $conversationid Conversation id.
     * @param string $route Route key the call belongs to (router|rewriter).
     * @param string $model Model actually called.
     * @param \block_openaiagent\ai\response $response Provider response.
     * @return void
     */
    public static function record_internal_usage(
        int $conversationid,
        string $route,
        string $model,
        \block_openaiagent\ai\response $response
    ): void {
        if ($conversationid <= 0 || !$response->success || $response->totaltokens <= 0) {
            return;
        }
        self::add_message($conversationid, 'system', '', [
            'route' => $route,
            'model' => $model,
            'openairesponseid' => $response->id,
            'prompttokens' => $response->prompttokens,
            'cachedtokens' => $response->cachedtokens,
            'completiontokens' => $response->completiontokens,
            'totaltokens' => $response->totaltokens,
        ]);
    }

    /**
     * Return the messages of an owned conversation in chronological order.
     *
     * @param int $conversationid Conversation id.
     * @param int $userid Expected owner.
     * @param int $courseid Expected course.
     * @param int $limit Maximum messages to return (0 = all).
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass[] Message records.
     */
    public static function get_messages(
        int $conversationid,
        int $userid,
        int $courseid,
        int $limit = 0,
        int $blockinstanceid = 0
    ): array {
        global $DB;
        if (self::get_owned($conversationid, $userid, $courseid, $blockinstanceid) === null) {
            return [];
        }
        return $DB->get_records(
            self::MESSAGES,
            ['conversationid' => $conversationid],
            'timecreated ASC, id ASC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Return the most recent user/assistant turns of a conversation as an
     * ordered list of {role, content} pairs suitable for use as Responses API
     * input when server-side conversation storage is disabled.
     *
     * Rows with empty content (e.g. error rows, or any row when message logging
     * is disabled) and non user/assistant roles are skipped. The result is in
     * chronological order and includes the latest user turn.
     *
     * @param int $conversationid Conversation id.
     * @param int $limit Maximum turns to return.
     * @return array[] List of ['role' => string, 'content' => string].
     */
    public static function recent_history(int $conversationid, int $limit = 6): array {
        global $DB;

        // Non-conversational rows (the usage records of the router and the query
        // rewriter) are excluded in SQL, not in PHP: they would otherwise eat
        // slots in the fetch window and silently shorten the history the model
        // gets back.
        $rows = $DB->get_records_select(
            self::MESSAGES,
            "conversationid = :cid AND role IN ('user', 'assistant')",
            ['cid' => $conversationid],
            'timecreated DESC, id DESC',
            'id, role, content',
            0,
            $limit * 2
        );

        $turns = [];
        foreach ($rows as $row) {
            $role = (string)$row->role;
            $content = (string)$row->content;
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
            if (count($turns) >= $limit) {
                break;
            }
        }

        return array_reverse($turns);
    }

    /**
     * Add a sentence to the end of the last assistant reply.
     *
     * Used when the server has something to add to what the model just wrote
     * and the two must not disagree. Updating the stored row as well as the
     * returned text is the point: otherwise the chat shows one thing now and a
     * different thing after a reload.
     *
     * @param int $conversationid Conversation id.
     * @param string $text Sentence to append.
     * @return void
     */
    public static function append_to_last_assistant(int $conversationid, string $text): void {
        global $DB;

        if ($conversationid <= 0 || trim($text) === '') {
            return;
        }

        $records = $DB->get_records_select(
            self::MESSAGES,
            "conversationid = :cid AND role = 'assistant'",
            ['cid' => $conversationid],
            'timecreated DESC, id DESC',
            'id, content',
            0,
            1
        );
        $record = reset($records);
        if (!$record) {
            return;
        }

        // Empty content means this profile does not store message text, and
        // appending to nothing would create a row that is only our half of a
        // conversation nobody kept.
        if (trim((string)$record->content) === '') {
            return;
        }

        $DB->set_field(self::MESSAGES, 'content', $record->content . "\n\n" . $text, ['id' => $record->id]);
    }

    /**
     * The text of the most recent assistant reply in a conversation.
     *
     * Returns an empty string when the profile does not store message text, so
     * every caller has to treat "no signal" as a normal outcome rather than as
     * an error.
     *
     * @param int $conversationid Conversation id.
     * @return string Reply text, or an empty string when there is none.
     */
    public static function last_assistant_message(int $conversationid): string {
        global $DB;

        if ($conversationid <= 0) {
            return '';
        }

        $records = $DB->get_records_select(
            self::MESSAGES,
            "conversationid = :cid AND role = 'assistant'",
            ['cid' => $conversationid],
            'timecreated DESC, id DESC',
            'id, content',
            0,
            1
        );
        $record = reset($records);

        return $record ? (string)$record->content : '';
    }

    /**
     * The most recent user messages in a conversation, newest first.
     *
     * @param int $conversationid Conversation id.
     * @param int $limit How many to return.
     * @return string[] Message texts.
     */
    public static function recent_user_messages(int $conversationid, int $limit = 6): array {
        global $DB;

        if ($conversationid <= 0 || $limit <= 0) {
            return [];
        }

        $records = $DB->get_records_select(
            self::MESSAGES,
            "conversationid = :cid AND role = 'user'",
            ['cid' => $conversationid],
            'timecreated DESC, id DESC',
            'id, content',
            0,
            $limit
        );

        $out = [];
        foreach ($records as $record) {
            $content = (string)$record->content;
            if ($content !== '') {
                $out[] = $content;
            }
        }

        return $out;
    }

    /**
     * How many user messages a conversation has had since a point in time.
     *
     * Counting rows rather than reading their text keeps this working on
     * profiles that do not store message content.
     *
     * @param int $conversationid Conversation id.
     * @param int $since Timestamp to count from.
     * @return int
     */
    public static function count_user_messages_since(int $conversationid, int $since): int {
        global $DB;

        if ($conversationid <= 0) {
            return 0;
        }

        return $DB->count_records_select(
            self::MESSAGES,
            "conversationid = :cid AND role = 'user' AND timecreated >= :since",
            ['cid' => $conversationid, 'since' => $since]
        );
    }

    /**
     * Delete all conversations and messages for a user in a course.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    public static function reset(int $userid, int $courseid, int $blockinstanceid = 0): void {
        global $DB;
        $conditions = [
            'userid' => $userid,
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ];
        $conversations = $DB->get_records(self::CONVERSATIONS, $conditions, '', 'id');
        if (!$conversations) {
            return;
        }
        $ids = array_keys($conversations);
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select(self::MESSAGES, "conversationid $insql", $params);
        $DB->delete_records(self::CONVERSATIONS, $conditions);
    }

    /**
     * Delete conversations (and their messages) not modified since a cutoff.
     *
     * Messages are removed before their parent conversations so no orphan
     * message records can be left behind. Deletes are batched to keep the
     * IN() clauses within database limits.
     *
     * @param int $cutoff Unix timestamp; conversations with timemodified below
     *                    this are purged. A non-positive value is a no-op.
     * @return int Number of conversations deleted.
     */
    public static function purge_older_than(int $cutoff): int {
        global $DB;
        if ($cutoff <= 0) {
            return 0;
        }
        $conversations = $DB->get_records_select(self::CONVERSATIONS, 'timemodified < ?', [$cutoff], '', 'id');
        if (!$conversations) {
            return 0;
        }
        $ids = array_keys($conversations);
        foreach (array_chunk($ids, 200) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $DB->delete_records_select(self::MESSAGES, "conversationid $insql", $params);
            $DB->delete_records_select(self::CONVERSATIONS, "id $insql", $params);
        }
        return count($ids);
    }
}
