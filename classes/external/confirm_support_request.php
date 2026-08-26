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
 * External function: confirm or decline a support escalation draft.
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
use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\course_config;
use block_openaiagent\local\support_gate;
use block_openaiagent\local\supportrequest;

/**
 * Acts on a support draft after the participant has clicked.
 *
 * This is the only place a support request can leave the draft state. The click
 * is what authorises it: nothing the model says, and nothing written in the
 * chat, can reach this code path. Every check is repeated here even though the
 * card was rendered by the same server, because the card travelled through the
 * browser and anything that travelled through the browser is a claim, not a
 * fact.
 */
class confirm_support_request extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'draftid' => new external_value(PARAM_INT, 'Support request id'),
            'token' => new external_value(PARAM_ALPHANUM, 'Single-use confirmation token'),
            'confirm' => new external_value(PARAM_BOOL, 'True to send, false to discard'),
        ]);
    }

    /**
     * Confirm or discard a draft.
     *
     * @param int $courseid Course id.
     * @param int $draftid Request id.
     * @param string $token Confirmation token.
     * @param bool $confirm Whether the participant confirmed.
     * @return array
     */
    public static function execute(int $courseid, int $draftid, string $token, bool $confirm): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'draftid' => $draftid,
            'token' => $token,
            'confirm' => $confirm,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/openaiagent:use', $context);
        require_capability('block/openaiagent:requestsupport', $context);

        $draft = supportrequest::claim_draft($params['draftid'], (int)$USER->id, $params['token']);
        if ($draft === null) {
            // One answer for "does not exist", "belongs to somebody else",
            // "already answered" and "expired". Telling them apart would let a
            // caller probe for other participants' drafts.
            return self::result(false, 'unavailable', '', get_string('support_confirm_unavailable', 'block_openaiagent'));
        }

        // The draft carries the course it was raised in. A request that arrives
        // naming a different course is refused rather than quietly acted on.
        if ((int)$draft->courseid !== (int)$params['courseid']) {
            return self::result(false, 'unavailable', '', get_string('support_confirm_unavailable', 'block_openaiagent'));
        }

        if (!$params['confirm']) {
            supportrequest::mark_cancelled((int)$draft->id);

            return self::result(
                true,
                supportrequest::STATUS_CANCELLED,
                (string)$draft->ticketref,
                get_string('support_confirm_cancelled', 'block_openaiagent'),
                (int)$draft->conversationid
            );
        }

        // The ceilings are re-evaluated at the moment of sending, not only when
        // the card was drawn: minutes may have passed, and in a massive course
        // that is long enough for the circuit breaker to have tripped.
        $config = course_config::resolve((int)$draft->courseid, (int)$draft->blockinstanceid);
        // Checked before the ceilings on purpose. A participant whose request is
        // already with the support team should be told so, with the reference,
        // rather than told "not right now" by whichever limit happens to trip
        // first -- and the conversation-scoped precondition below would trip on
        // exactly this case.
        $duplicate = supportrequest::find_duplicate(
            (int)$draft->courseid,
            (int)$USER->id,
            (string)$draft->summaryhash,
            (int)($config['support']['dedupehours'] ?? 0)
        );
        if ($duplicate !== null) {
            supportrequest::mark_cancelled((int)$draft->id);

            return self::result(
                true,
                'duplicate',
                (string)$duplicate->ticketref,
                get_string('support_confirm_duplicate', 'block_openaiagent', (object)[
                    'reference' => (string)$duplicate->ticketref,
                    'when' => userdate((int)$duplicate->timecreated),
                ]),
                (int)$draft->conversationid
            );
        }

        // This draft is excluded from the "is one already pending?" check: it is
        // the pending one. Without that exclusion the check short-circuits on it
        // and the ceilings below are never reached, which would make this whole
        // re-validation dead code.
        $blocked = support_gate::hard_preconditions(
            $config,
            (int)$draft->conversationid,
            (int)$USER->id,
            (int)$draft->courseid,
            (int)$draft->id
        );
        if ($blocked !== '') {
            supportrequest::mark_cancelled((int)$draft->id);

            return self::result(
                false,
                $blocked,
                (string)$draft->ticketref,
                self::refusal_message($blocked, $config),
                (int)$draft->conversationid
            );
        }

        supportrequest::mark_queued((int)$draft->id);

        // Delivery happens outside this request. A course in digest mode leaves
        // it for the scheduled run instead, which is what turns a spike of
        // hundreds of requests into a handful of emails.
        if (empty($config['support']['digestmode'])) {
            \block_openaiagent\task\send_support_request_task::queue((int)$draft->id);
        }

        // Deliberately "registered", never "sent": the message has not left yet.
        // Delivery, and the notification that follows it, belong to the task that
        // runs afterwards.
        $sla = trim((string)($config['support']['slatext'] ?? ''));
        $message = get_string('support_confirm_queued', 'block_openaiagent', (string)$draft->ticketref);
        if ($sla !== '') {
            $message .= ' ' . $sla;
        }

        return self::result(
            true,
            supportrequest::STATUS_QUEUED,
            (string)$draft->ticketref,
            $message,
            (int)$draft->conversationid
        );
    }

    /**
     * Message shown when the request can no longer be accepted.
     *
     * Falls back to the plain support link, which is what the block did before
     * this feature existed: a participant who cannot escalate must still be told
     * where to go.
     *
     * @param string $reason Gate reason.
     * @param array $config Effective course config.
     * @return string
     */
    private static function refusal_message(string $reason, array $config): string {
        $key = $reason === support_gate::DENIED_QUOTA || $reason === support_gate::DENIED_COOLDOWN
            ? 'support_confirm_overquota'
            : 'support_confirm_unavailablenow';
        $message = get_string($key, 'block_openaiagent');

        $url = trim((string)($config['support']['supporturl'] ?? ''));
        if ($url !== '') {
            $message .= ' ' . get_string('support_confirm_uselink', 'block_openaiagent', $url);
        }

        return $message;
    }

    /**
     * Shape one result.
     *
     * @param bool $success Whether the action was carried out.
     * @param string $status Resulting status or refusal reason.
     * @param string $reference Ticket reference.
     * @param string $message Text to show the participant.
     * @param int $conversationid Conversation to record it in (0 = none).
     * @return array
     */
    private static function result(
        bool $success,
        string $status,
        string $reference,
        string $message,
        int $conversationid = 0
    ): array {
        // Written into the conversation, not just returned. What the client
        // renders now has to survive a reload: otherwise the chat ends on the
        // model's "you still have to confirm this", which stops being true the
        // moment the participant clicks, and reads as a contradiction ever
        // after.
        conversation_repository::add_message($conversationid, 'assistant', $message, [
            'route' => 'assistant',
        ]);

        return [
            'success' => $success,
            'status' => $status,
            'reference' => $reference,
            'message' => $message,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the action was carried out'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Resulting status, or the reason it was refused'),
            'reference' => new external_value(PARAM_TEXT, 'Ticket reference, empty when there is none'),
            'message' => new external_value(PARAM_TEXT, 'Message to show the participant'),
        ]);
    }
}
