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
 * Delivers confirmed support requests.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Sends the escalation email and records what happened.
 *
 * Called from the tasks rather than from a web request, so a slow or unreachable
 * mail server never makes a participant wait in the chat, and a failure can be
 * retried instead of being lost.
 */
class support_delivery {
    /**
     * Deliver one request.
     *
     * @param \stdClass $request Support request record.
     * @return bool True when the mail server accepted it.
     */
    public static function send(\stdClass $request): bool {
        $config = course_config::resolve((int)$request->courseid, (int)$request->blockinstanceid);
        $addresses = support_composer::recipients($request, $config);

        if (empty($addresses['to'])) {
            // Nowhere to send it. Retrying will not conjure a destination, so
            // this is spent immediately rather than three times over.
            supportrequest::set_status((int)$request->id, supportrequest::STATUS_FAILED, [
                'attempts' => supportrequest::MAX_ATTEMPTS,
                'errormsg' => get_string('support_error_norecipients', 'block_openaiagent'),
            ]);
            support_notifier::failed($request);

            return false;
        }

        $subject = support_composer::subject($request, $config);
        $body = support_composer::body($request, $config);
        $participant = \core_user::get_user((int)$request->userid, '*', IGNORE_MISSING) ?: null;

        $sent = [];
        $failed = [];
        foreach (array_merge($addresses['to'], $addresses['cc']) as $address) {
            if (support_mailer::send_to($address, $subject, $body, $participant)) {
                $sent[] = $address;
            } else {
                $failed[] = $address;
            }
        }

        if (empty($sent)) {
            $exhausted = supportrequest::record_failed_attempt(
                (int)$request->id,
                get_string('support_error_refused', 'block_openaiagent', implode(', ', $failed))
            );
            // Only when there is nothing left to try. Announcing every failed
            // attempt would tell somebody their query had failed while it was
            // still perfectly likely to go out on the next run.
            if ($exhausted) {
                support_notifier::failed($request);
            }

            return false;
        }

        supportrequest::mark_sent((int)$request->id, $sent);
        self::copy_participant($request, $config, $subject, $body, $participant);
        self::log_sent($request);
        support_notifier::sent(supportrequest::get((int)$request->id) ?? $request);

        return true;
    }

    /**
     * Send the participant their own copy, when the profile asks for one.
     *
     * The copy is their receipt: it is the one piece of evidence they hold that
     * does not depend on trusting the chat.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @param string $subject Subject already composed.
     * @param string $body Body already composed.
     * @param \stdClass|null $participant Participant record.
     * @return void
     */
    private static function copy_participant(
        \stdClass $request,
        array $config,
        string $subject,
        string $body,
        ?\stdClass $participant
    ): void {
        if (empty($config['support']['copytouser']) || $participant === null) {
            return;
        }

        $intro = get_string('support_mail_copyintro', 'block_openaiagent', (string)$request->ticketref);
        // Passing the real account to email_to_user() here, so the participant's own
        // notification preferences and bounce handling apply as they should.
        email_to_user(
            $participant,
            \core_user::get_noreply_user(),
            $subject,
            $intro . "\n\n" . $body
        );
    }

    /**
     * Record the delivery in the site log.
     *
     * @param \stdClass $request Support request record.
     * @return void
     */
    private static function log_sent(\stdClass $request): void {
        try {
            \block_openaiagent\event\support_request_sent::make($request)->trigger();
        } catch (\Throwable $e) {
            // An audit failure must never turn a delivered message into a
            // failed one.
            debugging(
                'block_openaiagent could not log a support request: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Deliver a course's queued requests as one grouped message.
     *
     * What this is for: during a platform-wide incident a massive course can
     * produce hundreds of identical-ish requests in minutes. One digest every
     * few minutes turns that flood into a handful of readable emails, which is
     * the difference between a support team that can work and one that cannot.
     *
     * @param int $courseid Course id.
     * @param \stdClass[] $requests Queued requests for that course.
     * @return bool True when the mail server accepted the digest.
     */
    public static function send_digest(int $courseid, array $requests): bool {
        global $DB;

        if (empty($requests)) {
            return true;
        }

        $first = reset($requests);
        $config = course_config::resolve($courseid, (int)$first->blockinstanceid);
        $addresses = support_composer::recipients($first, $config);
        if (empty($addresses['to'])) {
            foreach ($requests as $request) {
                supportrequest::set_status((int)$request->id, supportrequest::STATUS_FAILED, [
                    'attempts' => supportrequest::MAX_ATTEMPTS,
                    'errormsg' => get_string('support_error_norecipients', 'block_openaiagent'),
                ]);
                support_notifier::failed($request);
            }

            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        $coursename = $course
            ? support_composer::multilang((string)$course->fullname, \context_course::instance($courseid))
            : (string)$courseid;

        $subject = support_mailer::flatten_header(get_string('support_digest_subject', 'block_openaiagent', (object)[
            'count' => count($requests),
            'course' => $coursename,
        ]));

        $parts = [get_string('support_digest_intro', 'block_openaiagent', (object)[
            'count' => count($requests),
            'course' => $coursename,
        ])];
        foreach ($requests as $request) {
            $parts[] = str_repeat('-', 60) . "\n" . support_composer::body($request, $config);
        }
        $body = implode("\n\n", $parts);

        // No Reply-To on a digest: it carries several participants, so a single
        // reply address would send an answer meant for one of them to another.
        // Each entry inside carries its own address instead.
        $sent = [];
        foreach (array_merge($addresses['to'], $addresses['cc']) as $address) {
            if (support_mailer::send_to($address, $subject, $body)) {
                $sent[] = $address;
            }
        }

        if (empty($sent)) {
            foreach ($requests as $request) {
                $exhausted = supportrequest::record_failed_attempt(
                    (int)$request->id,
                    get_string('support_error_refused', 'block_openaiagent', implode(', ', $addresses['to']))
                );
                if ($exhausted) {
                    support_notifier::failed($request);
                }
            }

            return false;
        }

        foreach ($requests as $request) {
            supportrequest::mark_sent((int)$request->id, $sent);
            support_notifier::sent(supportrequest::get((int)$request->id) ?? $request);
            $participant = \core_user::get_user((int)$request->userid, '*', IGNORE_MISSING) ?: null;
            self::copy_participant(
                $request,
                $config,
                support_composer::subject($request, $config),
                support_composer::body($request, $config),
                $participant
            );
            self::log_sent($request);
        }

        return true;
    }
}
