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
 * Tells the participant what became of their support request.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Closes the loop with the participant after a request is delivered.
 *
 * The chat told them the query was registered. Something has to tell them it
 * actually went out, and it cannot be the chat: by then they have very likely
 * closed the window. A Moodle notification reaches them wherever they are, in
 * the channel they have chosen, which is why the feature uses one instead of
 * inventing its own email.
 */
class support_notifier {
    /** @var string Message provider name declared in db/messages.php. */
    private const PROVIDER = 'supportrequest';

    /**
     * Tell the participant their request is on its way.
     *
     * @param \stdClass $request Support request record.
     * @return void
     */
    public static function sent(\stdClass $request): void {
        self::notify(
            $request,
            get_string('support_notify_sent_subject', 'block_openaiagent'),
            get_string('support_notify_sent_body', 'block_openaiagent', (object)[
                'reference' => (string)$request->ticketref,
                'course' => self::course_name((int)$request->courseid),
            ])
        );

        self::record_in_chat($request, get_string(
            'support_chat_sent',
            'block_openaiagent',
            (string)$request->ticketref
        ));
    }

    /**
     * Tell the participant it could not be delivered, and where to go instead.
     *
     * Silence here would be the worst outcome of all: they would spend days
     * waiting for an answer to a message that never left.
     *
     * @param \stdClass $request Support request record.
     * @return void
     */
    public static function failed(\stdClass $request): void {
        $config = course_config::resolve((int)$request->courseid, (int)$request->blockinstanceid);
        $body = get_string('support_notify_failed_body', 'block_openaiagent', (object)[
            'reference' => (string)$request->ticketref,
            'course' => self::course_name((int)$request->courseid),
        ]);

        $url = trim((string)($config['support']['supporturl'] ?? ''));
        if ($url !== '') {
            $body .= "\n\n" . get_string('support_confirm_uselink', 'block_openaiagent', $url);
        }

        self::notify($request, get_string('support_notify_failed_subject', 'block_openaiagent'), $body);

        self::record_in_chat($request, get_string(
            'support_chat_failed',
            'block_openaiagent',
            (string)$request->ticketref
        ));
    }

    /**
     * Leave the outcome in the conversation itself.
     *
     * The notification reaches them wherever they are, but the chat is where
     * they will look first, and a conversation that still ends on "you have to
     * confirm this" long after the message went out is simply wrong.
     *
     * @param \stdClass $request Support request record.
     * @param string $text Message to add.
     * @return void
     */
    private static function record_in_chat(\stdClass $request, string $text): void {
        conversation_repository::add_message(
            (int)$request->conversationid,
            'assistant',
            $text,
            ['route' => 'assistant']
        );
    }

    /**
     * Send one notification.
     *
     * @param \stdClass $request Support request record.
     * @param string $subject Subject line.
     * @param string $body Plain-text body.
     * @return void
     */
    private static function notify(\stdClass $request, string $subject, string $body): void {
        $user = \core_user::get_user((int)$request->userid, '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            return;
        }

        try {
            $message = new \core\message\message();
            $message->component = 'block_openaiagent';
            $message->name = self::PROVIDER;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = nl2br(s($body));
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->courseid = (int)$request->courseid;
            $message->contexturl = (new \moodle_url('/course/view.php', ['id' => (int)$request->courseid]))->out(false);
            $message->contexturlname = self::course_name((int)$request->courseid);

            message_send($message);
        } catch (\Throwable $e) {
            // The request itself was delivered; failing to announce it must not
            // turn that into an error, and must certainly not be retried in a
            // way that could resend the escalation.
            debugging(
                'block_openaiagent could not notify a participant: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Readable course name.
     *
     * @param int $courseid Course id.
     * @return string
     */
    private static function course_name(int $courseid): string {
        global $DB;

        $fullname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        if ($fullname === false) {
            return '';
        }

        return support_composer::multilang(
            (string)$fullname,
            \context_course::instance($courseid, IGNORE_MISSING) ?: null
        );
    }
}
