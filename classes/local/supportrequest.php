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
 * Support escalation requests: drafts, tokens and quota accounting.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Creates and inspects support escalation requests.
 *
 * A request is born as a draft when the assistant offers to escalate, and it
 * only leaves that state when the participant confirms with an explicit click.
 * Nothing here sends anything: delivery is a separate concern, so a draft can
 * be created, inspected and expired without any mail ever being possible.
 */
class supportrequest {
    /** @var string Requests table. */
    private const TABLE = 'block_openaiagent_supportreq';

    /** @var string Awaiting the participant's confirmation. */
    public const STATUS_DRAFT = 'draft';

    /** @var string Confirmed and waiting for delivery. */
    public const STATUS_QUEUED = 'queued';

    /** @var string Accepted by the mail server. Not proof of delivery. */
    public const STATUS_SENT = 'sent';

    /** @var string Delivery failed after every retry. */
    public const STATUS_FAILED = 'failed';

    /** @var string The participant declined the offer. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string The draft was never confirmed and timed out. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * @var string[] Statuses that consume the participant's daily allowance.
     *
     * A failed delivery does not count: the participant did everything right and
     * should not lose an attempt because the mail server was down.
     */
    public const COUNTED_STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT];

    /** @var int Longest a summary may be, in characters. */
    public const SUMMARY_MAX = 1500;

    /** @var int How long an unconfirmed draft stays usable, in seconds. */
    public const DRAFT_TTL = DAYSECS;

    /** @var string Category used when the model offers one that does not exist. */
    public const CATEGORY_FALLBACK = 'otro';

    /**
     * @var int Delivery attempts before a request is given up on.
     *
     * Deliberately small. A mail server that has refused three times is down or
     * misconfigured, not busy, and the participant is better served by being
     * told so than by a request that retries quietly for hours.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Normalise a summary for comparison.
     *
     * Deduplication has to survive the ways a participant rewrites the same
     * complaint: different casing, stray punctuation, doubled spaces, an accent
     * typed or not. What survives is the sequence of words.
     *
     * @param string $summary Raw summary.
     * @return string Normalised form, suitable for hashing.
     */
    public static function normalize_summary(string $summary): string {
        $text = \core_text::strtolower(trim($summary));
        // Accents are stripped so "conexion" and "conexión" hash alike.
        //
        // Through core_text and not through iconv('ASCII//TRANSLIT') directly:
        // that conversion is implementation-defined and gives different results
        // on different platforms, so the same text would hash one way on the
        // developer's machine and another on the server. Deduplication that
        // depends on the host's C library is not deduplication.
        $text = \core_text::specialtoascii($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', (string)$text);

        return trim((string)$text);
    }

    /**
     * Hash of the normalised summary, used to spot repeated requests.
     *
     * @param string $summary Raw summary.
     * @return string SHA-1 hash, or an empty string when nothing is left.
     */
    public static function summary_hash(string $summary): string {
        $normalized = self::normalize_summary($summary);

        return $normalized === '' ? '' : sha1($normalized);
    }

    /**
     * Clean a model-supplied summary.
     *
     * The model writes this text, so it is treated as untrusted input: tags are
     * stripped and the length is capped before it ever reaches the database.
     *
     * @param string $summary Raw summary as written by the model.
     * @return string
     */
    public static function clean_summary(string $summary): string {
        $clean = trim(html_to_text($summary, 0, false));
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        $clean = (string)$clean;

        if (\core_text::strlen($clean) > self::SUMMARY_MAX) {
            $clean = trim(\core_text::substr($clean, 0, self::SUMMARY_MAX));
        }

        return $clean;
    }

    /**
     * Coerce a category to one of the known values.
     *
     * The list is closed on purpose: a category can route a message to a
     * different mailbox, so an unrecognised one must never be taken at face
     * value.
     *
     * @param string $category Category proposed by the model.
     * @return string A valid category.
     */
    public static function clean_category(string $category): string {
        $category = \core_text::strtolower(trim($category));

        return in_array($category, defaults::SUPPORT_CATEGORIES, true) ? $category : self::CATEGORY_FALLBACK;
    }

    /**
     * Create a draft request awaiting the participant's confirmation.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id.
     * @param int $userid Participant id.
     * @param int $conversationid Conversation the request came from.
     * @param string $summary Incident summary written by the model.
     * @param string $category Proposed category.
     * @return \stdClass The stored draft, with its token and reference.
     */
    public static function create_draft(
        int $courseid,
        int $blockinstanceid,
        int $userid,
        int $conversationid,
        string $summary,
        string $category
    ): \stdClass {
        global $DB;

        $now = time();
        $summary = self::clean_summary($summary);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->blockinstanceid = $blockinstanceid;
        $record->userid = $userid;
        $record->conversationid = $conversationid;
        $record->category = self::clean_category($category);
        $record->summary = $summary;
        $record->summaryhash = self::summary_hash($summary);
        $record->status = self::STATUS_DRAFT;
        // 32 random bytes as hex: exactly the column width, and unguessable.
        // Single use is enforced by the status, not by this value being unique.
        $record->token = bin2hex(random_bytes(32));
        $record->tokenexpiry = $now + self::DRAFT_TTL;
        $record->ticketref = '';
        $record->recipients = null;
        $record->attempts = 0;
        $record->errormsg = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->timesent = 0;

        $record->id = $DB->insert_record(self::TABLE, $record);

        // The reference is only knowable once the row has an id.
        $record->ticketref = self::build_reference($courseid, (int)$record->id);
        $DB->set_field(self::TABLE, 'ticketref', $record->ticketref, ['id' => $record->id]);

        return $record;
    }

    /** @var string Reference prefix used when the site has not set one. */
    public const REFERENCE_PREFIX_DEFAULT = 'OA';

    /**
     * Human-readable reference shown to the participant.
     *
     * The prefix is configurable so an institution can use whatever its help
     * desk already recognises. Only new references are affected: the ones
     * already sent are stored on the row, because a reference an administrator
     * has quoted in an email must not change underneath them.
     *
     * @param int $courseid Course id.
     * @param int $id Request id.
     * @return string
     */
    public static function build_reference(int $courseid, int $id): string {
        return self::reference_prefix() . '-' . $courseid . '-' . $id;
    }

    /**
     * The configured reference prefix, reduced to characters that survive a
     * mail subject line and a search box.
     *
     * @return string
     */
    public static function reference_prefix(): string {
        $raw = (string)get_config('block_openaiagent', 'support_reference_prefix');
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw);

        return $clean !== '' ? \core_text::substr($clean, 0, 20) : self::REFERENCE_PREFIX_DEFAULT;
    }

    /**
     * Load a request by id.
     *
     * @param int $id Request id.
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Move a request to another status.
     *
     * @param int $id Request id.
     * @param string $status New status.
     * @param array $extra Additional fields to write.
     * @return void
     */
    public static function set_status(int $id, string $status, array $extra = []): void {
        global $DB;

        $record = (object)($extra + [
            'id' => $id,
            'status' => $status,
            'timemodified' => time(),
        ]);
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Record that the mail server accepted the message.
     *
     * "Sent" means exactly that and nothing more: the message was handed over.
     * Whether it reaches a human inbox is not something Moodle can observe.
     *
     * @param int $id Request id.
     * @param string[] $recipients Addresses it went to.
     * @return void
     */
    public static function mark_sent(int $id, array $recipients): void {
        self::set_status($id, self::STATUS_SENT, [
            'timesent' => time(),
            'recipients' => implode(', ', $recipients),
            'errormsg' => null,
        ]);
    }

    /**
     * Record a delivery attempt that did not work.
     *
     * The request is given up on once it has burned through its attempts;
     * until then it stays queued so the next run can try again.
     *
     * @param int $id Request id.
     * @param string $error What went wrong.
     * @return bool True when this was the last attempt and the request failed.
     */
    public static function record_failed_attempt(int $id, string $error): bool {
        $request = self::get($id);
        if ($request === null) {
            return true;
        }

        $attempts = (int)$request->attempts + 1;
        $exhausted = $attempts >= self::MAX_ATTEMPTS;

        self::set_status($id, $exhausted ? self::STATUS_FAILED : self::STATUS_QUEUED, [
            'attempts' => $attempts,
            'errormsg' => \core_text::substr($error, 0, 1000),
        ]);

        return $exhausted;
    }

    /**
     * Requests waiting to be delivered.
     *
     * @param int $courseid Restrict to one course (0 = every course).
     * @param int $olderthan Only those queued before this timestamp (0 = all).
     * @param int $limit Maximum to return.
     * @return \stdClass[]
     */
    public static function queued(int $courseid = 0, int $olderthan = 0, int $limit = 0): array {
        global $DB;

        $where = 'status = :status';
        $params = ['status' => self::STATUS_QUEUED];
        if ($courseid > 0) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($olderthan > 0) {
            $where .= ' AND timemodified <= :olderthan';
            $params['olderthan'] = $olderthan;
        }

        return $DB->get_records_select(self::TABLE, $where, $params, 'timecreated ASC', '*', 0, $limit);
    }

    /**
     * The courses that currently have something waiting to go out.
     *
     * @return int[] Course ids.
     */
    public static function courses_with_queued(): array {
        global $DB;

        $rows = $DB->get_records_select(
            self::TABLE,
            'status = :status',
            ['status' => self::STATUS_QUEUED],
            '',
            'DISTINCT courseid'
        );

        return array_map('intval', array_keys($rows));
    }

    /**
     * Whether the conversation already has a draft waiting to be answered.
     *
     * One draft may be excluded, which is what the confirmation path needs: the
     * draft being confirmed is itself pending, and without the exclusion this
     * check would report "there is already a draft" about the very draft the
     * participant just clicked.
     *
     * @param int $conversationid Conversation id.
     * @param int $ignoreid Draft to disregard (0 = none).
     * @return bool
     */
    public static function has_pending_draft(int $conversationid, int $ignoreid = 0): bool {
        global $DB;

        if ($conversationid <= 0) {
            return false;
        }

        $where = 'conversationid = :cid AND status = :status AND tokenexpiry > :now';
        $params = ['cid' => $conversationid, 'status' => self::STATUS_DRAFT, 'now' => time()];
        if ($ignoreid > 0) {
            $where .= ' AND id <> :ignoreid';
            $params['ignoreid'] = $ignoreid;
        }

        return $DB->record_exists_select(self::TABLE, $where, $params);
    }

    /**
     * The unanswered draft of a conversation, if there is one.
     *
     * @param int $conversationid Conversation id.
     * @return \stdClass|null
     */
    public static function pending_draft(int $conversationid): ?\stdClass {
        global $DB;

        if ($conversationid <= 0) {
            return null;
        }

        $records = $DB->get_records_select(
            self::TABLE,
            'conversationid = :cid AND status = :status AND tokenexpiry > :now',
            ['cid' => $conversationid, 'status' => self::STATUS_DRAFT, 'now' => time()],
            'id DESC',
            '*',
            0,
            1
        );
        $record = reset($records);

        return $record ?: null;
    }

    /**
     * Take a draft out of play, checking it really belongs to this participant.
     *
     * Everything is verified here rather than trusted from the request: the
     * ownership, the state, the expiry and the token. The token alone would be
     * enough to be unguessable, but it travels to the browser, so it is treated
     * as one factor and not as proof.
     *
     * @param int $id Request id.
     * @param int $userid Participant claiming it.
     * @param string $token Token presented by the client.
     * @return \stdClass|null The draft when it may be acted on, null otherwise.
     */
    public static function claim_draft(int $id, int $userid, string $token): ?\stdClass {
        $draft = self::get($id);
        if ($draft === null) {
            return null;
        }
        if ((int)$draft->userid !== $userid) {
            return null;
        }
        if ((string)$draft->status !== self::STATUS_DRAFT) {
            return null;
        }
        if ((int)$draft->tokenexpiry > 0 && (int)$draft->tokenexpiry < time()) {
            return null;
        }
        if ($token === '' || !hash_equals((string)$draft->token, $token)) {
            return null;
        }

        return $draft;
    }

    /**
     * Mark a draft as confirmed and awaiting delivery.
     *
     * The token is cleared so the same click cannot be replayed.
     *
     * @param int $id Request id.
     * @return void
     */
    public static function mark_queued(int $id): void {
        self::set_status($id, self::STATUS_QUEUED, ['token' => '']);
    }

    /**
     * Mark a draft as declined by the participant.
     *
     * @param int $id Request id.
     * @return void
     */
    public static function mark_cancelled(int $id): void {
        self::set_status($id, self::STATUS_CANCELLED, ['token' => '']);
    }

    /**
     * Whether this conversation already produced a request very recently.
     *
     * Stops a conversation from generating a second ticket about the same
     * session moments after the first one went out. The window is short on
     * purpose: two genuinely different problems raised in one conversation both
     * deserve to reach the support team, and the guards that matter -- the daily
     * allowance, the cooldown and the deduplication by content -- are doing that
     * work already. A long lockout here only produced a participant being told
     * "you already have one" with no idea when that would stop being true.
     *
     * @param int $conversationid Conversation id.
     * @param int $seconds Window to look back over.
     * @return bool
     */
    public static function has_recent_request(int $conversationid, int $seconds): bool {
        global $DB;

        if ($conversationid <= 0 || $seconds <= 0) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::COUNTED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['cid'] = $conversationid;
        $params['since'] = time() - $seconds;

        return $DB->record_exists_select(
            self::TABLE,
            "conversationid = :cid AND status $insql AND timecreated >= :since",
            $params
        );
    }

    /**
     * An earlier request from this participant saying the same thing.
     *
     * Deduplication works on the meaning of the request rather than on the
     * conversation it came from: the case it exists for is somebody who asks,
     * gets no answer fast enough, opens the chat again and asks the same thing
     * in slightly different words. The normalised hash catches that; a
     * conversation-scoped check never would.
     *
     * Only requests that actually went somewhere count. A draft nobody confirmed
     * is not a duplicate of anything, and a failed delivery must not stop the
     * participant trying again.
     *
     * @param int $courseid Course id.
     * @param int $userid Participant id.
     * @param string $summaryhash Hash of the normalised summary.
     * @param int $hours Window to look back over.
     * @return \stdClass|null The earlier request, or null when there is none.
     */
    public static function find_duplicate(int $courseid, int $userid, string $summaryhash, int $hours): ?\stdClass {
        global $DB;

        if ($summaryhash === '' || $hours <= 0) {
            return null;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::COUNTED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['courseid'] = $courseid;
        $params['userid'] = $userid;
        $params['hash'] = $summaryhash;
        $params['since'] = time() - ($hours * HOURSECS);

        $records = $DB->get_records_select(
            self::TABLE,
            "courseid = :courseid AND userid = :userid AND summaryhash = :hash"
                . " AND status $insql AND timecreated >= :since",
            $params,
            'timecreated DESC',
            '*',
            0,
            1
        );
        $record = reset($records);

        return $record ?: null;
    }

    /**
     * When the participant last declined an offer in this conversation.
     *
     * @param int $conversationid Conversation id.
     * @return int Timestamp, or 0 when they never declined one.
     */
    public static function last_refusal_time(int $conversationid): int {
        global $DB;

        if ($conversationid <= 0) {
            return 0;
        }

        $records = $DB->get_records_select(
            self::TABLE,
            'conversationid = :cid AND status = :status',
            ['cid' => $conversationid, 'status' => self::STATUS_CANCELLED],
            'timemodified DESC',
            'id, timemodified',
            0,
            1
        );
        $record = reset($records);

        return $record ? (int)$record->timemodified : 0;
    }

    /**
     * How many requests the participant has already sent today in this course.
     *
     * @param int $courseid Course id.
     * @param int $userid Participant id.
     * @return int
     */
    public static function count_user_today(int $courseid, int $userid): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::COUNTED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['courseid'] = $courseid;
        $params['userid'] = $userid;
        $params['since'] = usergetmidnight(time());

        return $DB->count_records_select(
            self::TABLE,
            "courseid = :courseid AND userid = :userid AND status $insql AND timecreated >= :since",
            $params
        );
    }

    /**
     * Seconds since the participant's last request in this course.
     *
     * @param int $courseid Course id.
     * @param int $userid Participant id.
     * @return int|null Seconds, or null when they have never sent one.
     */
    public static function seconds_since_last(int $courseid, int $userid): ?int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::COUNTED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['courseid'] = $courseid;
        $params['userid'] = $userid;

        $records = $DB->get_records_select(
            self::TABLE,
            "courseid = :courseid AND userid = :userid AND status $insql",
            $params,
            'timecreated DESC',
            'id, timecreated',
            0,
            1
        );
        $record = reset($records);

        return $record ? max(0, time() - (int)$record->timecreated) : null;
    }

    /**
     * How many requests the whole course has produced today.
     *
     * @param int $courseid Course id.
     * @return int
     */
    public static function count_course_today(int $courseid): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::COUNTED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params['courseid'] = $courseid;
        $params['since'] = usergetmidnight(time());

        return $DB->count_records_select(
            self::TABLE,
            "courseid = :courseid AND status $insql AND timecreated >= :since",
            $params
        );
    }

    /**
     * The participant's most recent requests in a course.
     *
     * Only ever the caller's own rows, and only the fields they may see: no
     * destination addresses, no delivery internals.
     *
     * @param int $courseid Course id.
     * @param int $userid Participant id.
     * @param int $limit How many to return.
     * @return array[] Reference, category, status and dates.
     */
    public static function recent_for_user(int $courseid, int $userid, int $limit = 5): array {
        global $DB;

        $records = $DB->get_records_select(
            self::TABLE,
            'courseid = :courseid AND userid = :userid AND status <> :draft',
            ['courseid' => $courseid, 'userid' => $userid, 'draft' => self::STATUS_DRAFT],
            'timecreated DESC',
            'id, ticketref, category, status, timecreated, timesent',
            0,
            $limit
        );

        $out = [];
        foreach ($records as $record) {
            $out[] = [
                'reference' => (string)$record->ticketref,
                'category' => (string)$record->category,
                'status' => (string)$record->status,
                'created' => userdate((int)$record->timecreated),
                'sent' => (int)$record->timesent > 0 ? userdate((int)$record->timesent) : '',
            ];
        }

        return $out;
    }

    /**
     * Expire drafts the participant never answered.
     *
     * @param int $now Reference time.
     * @return int Number of drafts expired.
     */
    public static function expire_drafts(int $now = 0): int {
        global $DB;

        $now = $now > 0 ? $now : time();
        $stale = $DB->get_fieldset_select(
            self::TABLE,
            'id',
            'status = :status AND tokenexpiry > 0 AND tokenexpiry < :now',
            ['status' => self::STATUS_DRAFT, 'now' => $now]
        );
        if (empty($stale)) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($stale, SQL_PARAMS_NAMED, 'id');
        $params['status'] = self::STATUS_EXPIRED;
        $params['now'] = $now;
        $DB->execute(
            "UPDATE {" . self::TABLE . "} SET status = :status, token = '', timemodified = :now WHERE id $insql",
            $params
        );

        return count($stale);
    }
}
